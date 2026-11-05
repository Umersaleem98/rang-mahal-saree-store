<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:send-emails {--limit=50}', function () {
    $sent=\App\Support\OrderEmails::deliver(max(1,min(200,(int)$this->option('limit'))));
    $this->info("Sent {$sent} order emails.");
})->purpose('Deliver pending order notifications with bounded retries');

Artisan::command('media:prune {--days=7} {--execute}',function(){
 $days=max(1,(int)$this->option('days'));$candidates=\Illuminate\Support\Facades\DB::table('media_assets')->whereNull('trashed_at')->where('created_at','<',now()->subDays($days))->pluck('id');$count=0;
 foreach($candidates as $id)\Illuminate\Support\Facades\DB::transaction(function()use($id,&$count){$a=\Illuminate\Support\Facades\DB::table('media_assets')->where('id',$id)->lockForUpdate()->first();if(!$a||$a->trashed_at)return;
  if(\Illuminate\Support\Facades\DB::table('product_media')->where('asset_id',$id)->exists()||\Illuminate\Support\Facades\DB::table('products')->where(fn($q)=>$q->where('image',$a->path)->orWhere('video_url',$a->path)->orWhereJsonContains('images',$a->path))->exists()||\Illuminate\Support\Facades\DB::table('categories')->where('image',$a->path)->exists())return;
  if(!preg_match('~^/media/(products|videos)/([a-f0-9]{32}\.(webp|mp4|webm))$~D',$a->path,$m))return;
  $count++;if(!$this->option('execute'))return;$from=storage_path('app/public/'.$m[1].'/'.$m[2]);$directory=storage_path('app/private/media-trash/'.date('Y-m-d'));\Illuminate\Support\Facades\File::ensureDirectoryExists($directory,0700);if(is_file($from))\Illuminate\Support\Facades\File::move($from,$directory.'/'.$m[2]);\Illuminate\Support\Facades\DB::table('media_assets')->where('id',$id)->update(['trashed_at'=>now()]);
 });$this->info(($this->option('execute')?'Quarantined ':'Dry run: eligible ').$count.' unreferenced managed uploads. Legacy/unregistered files are never touched.');
})->purpose('Review or quarantine old unreferenced managed uploads; dry run by default');
