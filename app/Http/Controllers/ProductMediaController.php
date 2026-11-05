<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessSettings;

class ProductMediaController extends Controller
{
    public function upload(Request $r)
    {
        if($r->hasFile('video'))return $this->video($r);
        $limit=(int)BusinessSettings::get()['max_product_images'];
        $r->validate(['images' => 'required|array|min:1|max:'.$limit, 'images.*' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:8192|dimensions:max_width=4096,max_height=4096']);
        $directory = storage_path('app/public/products');
        File::ensureDirectoryExists($directory, 0750);
        $paths = [];
        foreach ($r->file('images') as $file) {
            [$sourceWidth, $sourceHeight] = getimagesize($file->getRealPath());
            abort_if($sourceWidth * $sourceHeight > 12000000, 422, 'Use images up to 12 megapixels.');
            $source = imagecreatefromstring(file_get_contents($file->getRealPath()));
            abort_unless($source, 422, 'This image could not be read.');
            if ($file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
                if (in_array($orientation, [2, 4, 5, 7])) imageflip($source, IMG_FLIP_HORIZONTAL);
                $degrees = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
                if ($degrees) { $rotated = imagerotate($source, $degrees, 0); imagedestroy($source); $source = $rotated; }
            }
            $scale = min(1, 4096 / max(imagesx($source), imagesy($source)));
            $width = max(1, (int) round(imagesx($source) * $scale)); $height = max(1, (int) round(imagesy($source) * $scale));
            $output = imagecreatetruecolor($width, $height);
            imagealphablending($output, false); imagesavealpha($output, true);
            imagefill($output, 0, 0, imagecolorallocatealpha($output, 0, 0, 0, 127));
            imagecopyresampled($output, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
            $name = bin2hex(random_bytes(16)).'.webp';
            $saved = imagewebp($output, $directory.'/'.$name, 90);
            imagedestroy($source); imagedestroy($output);
            unset($source, $output);
            abort_unless($saved && filesize($directory.'/'.$name) > 0, 500, 'Image processing failed.');
            chmod($directory.'/'.$name, 0640);
            $paths[] = '/media/products/'.$name;
            DB::table('media_assets')->insert(['path'=>'/media/products/'.$name,'kind'=>'image','mime'=>'image/webp','width'=>$width,'height'=>$height,'bytes'=>filesize($directory.'/'.$name),'uploaded_by'=>$r->user()->id]);
        }
        return response()->json(['images' => $paths], 201);
    }

    private function video(Request $r)
    {
        $limit=(int)BusinessSettings::get()['max_product_video_mb']*1024;
        $r->validate(['video'=>"required|file|mimes:mp4,webm|mimetypes:video/mp4,video/webm|max:$limit",'images'=>'prohibited']);
        $file=$r->file('video');$ext=$file->getMimeType()==='video/webm'?'webm':'mp4';$name=bin2hex(random_bytes(16)).'.'.$ext;$directory=storage_path('app/public/videos');File::ensureDirectoryExists($directory,0750);if(PHP_OS_FAMILY==='Windows')chmod($directory,0777);$file->move($directory,$name);chmod($directory.'/'.$name,0640);$path='/media/videos/'.$name;
        DB::table('media_assets')->insert(['path'=>$path,'kind'=>'video','mime'=>$ext==='webm'?'video/webm':'video/mp4','bytes'=>filesize($directory.'/'.$name),'uploaded_by'=>$r->user()->id]);
        return response()->json(['video'=>$path],201);
    }
    public function videoFile(string $file)
    {
        abort_unless(preg_match('/^[a-f0-9]{32}\.(mp4|webm)$/D',$file),404);$path=storage_path('app/public/videos/'.$file);abort_unless(is_file($path),404);
        return response()->file($path,['Content-Type'=>str_ends_with($file,'.webm')?'video/webm':'video/mp4','Cache-Control'=>'public, max-age=31536000, immutable','X-Content-Type-Options'=>'nosniff']);
    }
    public function index()
    {
        $assets=DB::table('media_assets')->whereNull('trashed_at')->orderByDesc('id')->limit(200)->get()->map(function($a){$a->products=DB::table('product_media')->where('asset_id',$a->id)->count();return $a;});
        $known=$assets->pluck('path');$legacy=collect();foreach(DB::table('products')->get(['id','image','images','video_url']) as $p)foreach(array_filter([...(json_decode($p->images??'[]',true)?:[$p->image]),$p->video_url]) as $path)if(!$known->contains($path))$legacy->push(['id'=>'legacy-'.$p->id.'-'.md5($path),'path'=>$path,'kind'=>$path===$p->video_url?'video':'image','bytes'=>null,'width'=>null,'height'=>null,'products'=>1]);
        return response()->json(['assets'=>$assets->concat($legacy->unique('path'))->values()]);
    }

    public function show(string $file)
    {
        abort_unless(preg_match('/^[a-f0-9]{32}\.webp$/D', $file), 404);
        $path = storage_path('app/public/products/'.$file);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
