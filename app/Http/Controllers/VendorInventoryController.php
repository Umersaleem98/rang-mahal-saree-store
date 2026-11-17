<?php

namespace App\Http\Controllers;

use App\Support\{BusinessSettings, MediaLibrary, SizeGuide, StoreCatalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\Rule;

class VendorInventoryController extends Controller
{
    public function handle(Request $request, string $path)
    {
        $user = $request->user();
        $owner = $user?->role === 'super_admin';
        abort_unless($owner || $user?->role === 'vendor', 403);
        $method = $request->method();

        if ($path === 'accounts') {
            abort_unless($owner && in_array($method, ['POST', 'PUT']), 403);
            $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
            $fields = $request->validate([
                'id' => $method === 'PUT' ? 'required|integer' : 'prohibited',
                'name' => 'required|string|max:100',
                'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($request->input('id'))],
                'password' => ($method === 'POST' ? 'required' : 'nullable').'|string|min:12|max:128',
                'active' => 'required|boolean',
                'contact_person'=>'nullable|string|max:100', 'contact_email'=>'nullable|email|max:190',
                'phone'=>'nullable|string|max:40', 'address'=>'nullable|string|max:2000',
            ]);
            $account = ['name' => $fields['name'], 'email' => strtolower($fields['email']), 'active' => $fields['active']];
            if (!empty($fields['password'])) $account['password_hash'] = Hash::make($fields['password']);
            DB::transaction(function () use ($method,$fields,$account) {
                if ($method === 'POST') {
                    $id=DB::table('users')->insertGetId($account + ['role'=>'vendor','created_at'=>now(),'updated_at'=>now()]);
                } else {
                    $id=$fields['id'];
                    $query=DB::table('users')->where('id',$id)->where('role','vendor');
                    abort_unless($query->lockForUpdate()->first(),404);
                    $query->update($account+['auth_version'=>DB::raw('auth_version + 1'),'updated_at'=>now()]);
                }
                DB::table('vendor_profiles')->updateOrInsert(['user_id'=>$id],[
                    'contact_person'=>$fields['contact_person']??null,'email'=>$fields['contact_email']??null,
                    'phone'=>$fields['phone']??null,'address'=>$fields['address']??null,
                ]);
            });

            return response()->json(['ok' => true]);
        }

        if ($path === 'media' && $method === 'POST') {
            abort_if($request->hasFile('video'), 422);
            return app(ProductMediaController::class)->upload($request);
        }

        if ($path === 'data' && $method === 'GET') {
            $listings = DB::table('vendor_listings as listing')
                ->leftJoin('products as product', 'product.id', '=', 'listing.product_id')
                ->when(!$owner, fn ($q) => $q->where('listing.vendor_id', $user->id))
                ->orderByDesc('listing.id')->get(['listing.*', 'product.stock as live_stock', 'product.active as live_active', 'product.price as live_price'])
                ->map(function ($listing) {
                    $listing->code = $listing->inventory_code ?? $this->code($listing->id);
                    unset($listing->show_code);
                    $listing->details = json_decode($listing->details, true, flags: JSON_THROW_ON_ERROR);
                    $finance=DB::table('vendor_submission_finances')->where('listing_id',$listing->id)->first(['purchase_cost','margin','payable','proposed_price']);
                    if ($finance) $listing->details=array_replace($listing->details,(array)$finance);
                    $listing->details['images']=DB::table('vendor_submission_images')->where('listing_id',$listing->id)->orderBy('position')->pluck('path')->all();
                    unset($listing->details['margin_type']);
                    return $listing;
                });
            if (!$owner) $listings->each(function ($row) { unset($row->product_id,$row->live_stock,$row->live_active,$row->live_price,$row->retail_price); });
            $result = ['listings' => $listings, 'categories' => DB::table('categories')->pluck('name')];
            if ($owner) {
                $result['vendors'] = DB::table('users as u')->leftJoin('vendor_profiles as p','p.user_id','=','u.id')
                    ->where('u.role','vendor')->get(['u.id','u.name','u.email','u.active','p.contact_person','p.email as contact_email','p.phone','p.address']);
                $result['purchases']=DB::table('vendor_purchases')->orderByDesc('id')->get();
                $result['payments']=DB::table('vendor_payments')->orderByDesc('id')->get();
                $result['sales']=DB::table('vendor_order_payables as p')->join('orders as o','o.id','=','p.order_id')
                    ->where('o.status','Delivered')->get(['p.listing_id','p.quantity','p.unit_payable','p.id']);
                $result['storefront']=DB::table('products')->whereNotIn('id', DB::table('vendor_listings')->whereNotNull('product_id')->select('product_id'))
                    ->orderBy('name')->get(['id','name','publication_status']);
                $result['storefront_count']=DB::table('products')->where('active',true)->where('publication_status','published')->count();
                $result['reviews']=DB::table('vendor_reviews')->orderByDesc('id')->get();
                $result['codes']=DB::table('vendor_inventory_codes')->orderByDesc('id')->get(['listing_id','code','created_at']);

            }

            return response()->json($result);
        }

        if (in_array($path,['link','purchase','payment'],true) && $method==='POST') {
            abort_unless($owner,403);
            return $this->record($request,$path);
        }

        if ($path === 'stock' && $method === 'PUT') {
            $v=$request->validate(['id'=>'required|integer','stock'=>'required|integer|min:0|max:1000000']);
            return DB::transaction(function () use ($v,$owner,$user) {
                $row=DB::table('vendor_listings')->where('id',$v['id'])->when(!$owner,fn($q)=>$q->where('vendor_id',$user->id))->lockForUpdate()->first();
                abort_unless($row,404);
                $details=json_decode($row->details,true,flags:JSON_THROW_ON_ERROR);
                $details['stock']=$v['stock'];
                DB::table('vendor_listings')->where('id',$row->id)->update(['details'=>json_encode($details,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
                return response()->json(['ok'=>true]);
            });
        }

        if ($path === 'review' && $method === 'PUT') {
            abort_unless($owner, 403);
            return $this->review($request);
        }

        if ($path === 'listings' && in_array($method, ['POST', 'PUT'])) {
            return $this->saveListing($request, $owner);
        }

        abort(404);
    }

    private function code(int $id, string $category = 'Saree'): string
    {
        $known=['saree'=>'SAR','unstitched saree'=>'UNS','stitched saree'=>'STC','blouse'=>'BLS','dupatta'=>'DUP','suit'=>'SUT'];
        $slug=strtolower(trim($category));
        $parts=preg_split('/[^a-z0-9]+/',$slug,-1,PREG_SPLIT_NO_EMPTY);
        $base=count($parts)>1?implode('',array_map(fn($part)=>$part[0],$parts)):substr($parts[0]??'CAT',0,3);
        $abbreviation=$known[$slug]??strtoupper($base ?: 'CAT');
        return 'HS-'.substr($abbreviation,0,12).'-'.str_pad((string) $id,4,'0',STR_PAD_LEFT);
    }

    private function saveListing(Request $r, bool $owner)
    {
        $data = $r->validate([
            'id' => $r->isMethod('PUT') ? 'required|integer' : 'prohibited',
            'vendor_id' => $owner ? 'nullable|integer' : 'prohibited',
            'name' => 'required|string|max:150', 'category' => 'required|exists:categories,name',
            'type' => 'required|in:Stitched,Unstitched', 'description' => 'required|string|max:10000',
            'fabric' => 'required|string|max:100', 'color' => 'required|string|max:50',
            'sizes' => 'required_if:type,Stitched|array|max:6',
            'sizes.*' => ['distinct', Rule::in(SizeGuide::SIZES)],
            'stock' => 'required|integer|min:0|max:1000000',
            'expected_stock' => 'nullable|integer|min:0|max:1000000',
            'images' => 'required|array|min:1|max:'.BusinessSettings::get()['max_product_images'],
            'images.*' => ['distinct', 'regex:~^/media/products/[a-f0-9]{32}\.webp$~D'],
            'purchase_cost' => 'nullable|numeric|decimal:0,2|min:0|max:10000000',
            'margin' => 'required|numeric|decimal:0,2|min:0|max:10000000',
            'proposed_price' => 'required|numeric|decimal:0,2|min:0|max:10000000',
            'payable' => 'required|numeric|decimal:0,2|min:0|max:10000000',
            'retail_price' => 'prohibited', 'code' => 'prohibited',
        ]);

        return DB::transaction(function () use ($r, $owner, $data) {
            $listing = isset($data['id']) ? DB::table('vendor_listings')->where('id', $data['id'])
                ->when(!$owner, fn ($query) => $query->where('vendor_id', $r->user()->id))->lockForUpdate()->first() : null;
            abort_if(isset($data['id']) && !$listing, 404);
            abort_if($listing && !$owner && !in_array($listing->status,['Pending','Changes Requested'],true),409,'Only pending or returned submissions can be edited.');
            abort_if($listing && !$owner && $listing->inventory_code && json_decode($listing->details,true)['category'] !== $data['category'],422,'Ask the owner to change this submission category.');
            $vendor = $listing->vendor_id ?? ($owner ? ($data['vendor_id'] ?? null) : $r->user()->id);
            abort_unless(DB::table('users')->where('id', $vendor)->where('role', 'vendor')->exists(), 422, 'Select a vendor.');
            $previousImages = $listing ? DB::table('vendor_submission_images')->where('listing_id',$listing->id)->pluck('path')->all() : [];
            $newImages = array_diff($data['images'], $previousImages);
            $images = DB::table('media_assets')->whereIn('path', $newImages)->whereNull('trashed_at')
                ->where('kind', 'image')->whereIn('uploaded_by', [$vendor, $r->user()->id])->lockForUpdate()->get(['id'])->count();
            abort_unless($images === count($newImages), 422, 'Choose images uploaded by this account.');
            $details = collect($data)->except(['id','vendor_id','expected_stock','images','purchase_cost','margin','payable','proposed_price'])->all();
            $values = ['details' => json_encode($details, JSON_THROW_ON_ERROR), 'status' => 'Pending', 'updated_at' => now()];
            if ($listing) {
                DB::table('vendor_listings')->where('id', $listing->id)->update($values);
                $id = $listing->id;
            } else {
                $id = DB::table('vendor_listings')->insertGetId($values + ['vendor_id' => $vendor, 'created_at' => now()]);
            }

            DB::table('vendor_submission_images')->where('listing_id',$id)->delete();
            foreach ($data['images'] as $position=>$path) DB::table('vendor_submission_images')->insert(['listing_id'=>$id,'path'=>$path,'position'=>$position]);
            DB::table('vendor_submission_finances')->updateOrInsert(['listing_id'=>$id],[
                'purchase_cost'=>$data['purchase_cost']??null,'margin'=>$data['margin'],'payable'=>$data['payable'],'proposed_price'=>$data['proposed_price'],
            ]);
            $code=$listing->inventory_code ?? null;
            $oldCategory=$listing ? json_decode($listing->details,true,flags:JSON_THROW_ON_ERROR)['category'] : null;
            if (!$code || ($owner && $oldCategory !== $data['category'])) {
                $sequence=DB::table('vendor_inventory_codes')->insertGetId(['listing_id'=>$id,'created_at'=>now()]);
                $code=$this->code($sequence,$data['category']);
                DB::table('vendor_inventory_codes')->where('id',$sequence)->update(['code'=>$code]);
                DB::table('vendor_listings')->where('id',$id)->update(['inventory_code'=>$code]);
            }
            return response()->json(['id'=>$id,'code'=>$code]);
        }, 3);
    }

    private function review(Request $r)
    {
        $data=$r->validate([
            'id'=>'required|integer','status'=>'required|in:Approved,Rejected,Changes Requested',
            'approved_payable'=>'required_if:status,Approved|nullable|numeric|decimal:0,2|min:0|max:10000000',
            'reason'=>'required_if:status,Changes Requested|nullable|string|max:2000',
        ]);
        return DB::transaction(function () use ($data,$r) {
            $row=DB::table('vendor_listings')->where('id',$data['id'])->lockForUpdate()->first();
            abort_unless($row,404);
            $price=$data['status']==='Approved'?$data['approved_payable']:$row->approved_payable;
            DB::table('vendor_listings')->where('id',$row->id)->update([
                'status'=>$data['status'],'approved_payable'=>$price,'review_reason'=>$data['reason']??null,'updated_at'=>now(),
            ]);
            DB::table('vendor_reviews')->insert([
                'listing_id'=>$row->id,'reviewer_id'=>$r->user()->id,'status'=>$data['status'],
                'approved_price'=>$price,'reason'=>$data['reason']??null,'created_at'=>now(),
            ]);
            return response()->json(['ok'=>true]);
        },3);
    }

    private function record(Request $r,string $path)
    {
        $data=$r->validate(match($path){
            'payment'=>['purchase_id'=>'required|integer','amount'=>'required|numeric|decimal:0,2|min:0.01|max:1000000000'],
            'purchase'=>['id'=>'required|integer','quantity'=>'required|integer|min:1|max:10000'],
            default=>['id'=>'required|integer','product_id'=>'required|integer|exists:products,id'],
        });
        return DB::transaction(function () use ($data,$path) {
            if ($path==='payment') {
                $purchase=DB::table('vendor_purchases')->where('id',$data['purchase_id'])->lockForUpdate()->first();
                abort_unless($purchase,404);
                $paid=DB::table('vendor_payments')->where('purchase_id',$purchase->id)->sum('amount');
                $balance=(int)round($purchase->unit_price*100)*$purchase->quantity-(int)round($paid*100);
                abort_if((int)round($data['amount']*100)>$balance,422,'Payment exceeds outstanding balance.');
                DB::table('vendor_payments')->insert($data+['paid_at'=>now()]);
            } else {
                $row=DB::table('vendor_listings')->where('id',$data['id'])->lockForUpdate()->first();
                abort_unless($row,404);
                abort_unless($row->status==='Approved' && $row->approved_payable!==null,422,'Approve the submission first.');
                if ($path==='purchase') {
                    DB::table('vendor_purchases')->insert(['listing_id'=>$row->id,'quantity'=>$data['quantity'],'unit_price'=>$row->approved_payable,'received_at'=>now()]);
                } else {
                    DB::table('products')->where('id',$data['product_id'])->lockForUpdate()->first();
                    abort_if($row->product_id,409,'This submission is already linked.');
                    abort_if(DB::table('vendor_listings')->where('product_id',$data['product_id'])->exists(),409,'This storefront product is already linked.');
                    DB::table('vendor_listings')->where('id',$row->id)->update(['product_id'=>$data['product_id'],'updated_at'=>now()]);
                }
            }
            return response()->json(['ok'=>true]);
        },3);
    }
}
