<?php
declare(strict_types=1);

namespace SnackQuest\Services;

use SnackQuest\Config;
use SnackQuest\Support\Logger;

final class UploadService
{
    public function __construct(private readonly Config $config, private readonly Logger $log)
    {
    }

    /** @return array{path:string,mime:string,width:int,height:int}|null */
    public function image(array $file, int $userId, string $kind='review'): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new \InvalidArgumentException('Das Bild konnte nicht hochgeladen werden.');
        $max=(int)$this->config->get('uploads.max_bytes',8_000_000);if((int)($file['size']??0)<1||(int)$file['size']>$max)throw new \InvalidArgumentException('Das Bild ist zu groß. Maximal 8 MB sind erlaubt.');
        $tmp=(string)$file['tmp_name'];$finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if(!isset($allowed[$mime]))throw new \InvalidArgumentException('Bitte lade ein JPEG-, PNG- oder WebP-Bild hoch.');
        $size=@getimagesize($tmp);$maxPixels=(int)$this->config->get('uploads.max_pixels',20_000_000);
        if(!$size||$size[0]<1||$size[1]<1||$size[0]>8000||$size[1]>8000||((int)$size[0]*(int)$size[1])>$maxPixels)throw new \InvalidArgumentException('Die Bilddatei ist ungültig oder hat zu viele Bildpunkte.');
        $root=rtrim((string)$this->config->get('uploads.dir',dirname(__DIR__,2).'/storage/uploads'),'\\/');$dir=$root.'/'.$userId;
        if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Upload-Verzeichnis nicht verfügbar.');
        $id=bin2hex(random_bytes(16));$relative=$userId.'/'.$kind.'-'.$id.'.webp';$dest=$root.'/'.$relative;$storedMime='image/webp';
        $source=match($mime){'image/jpeg'=>function_exists('imagecreatefromjpeg')?@imagecreatefromjpeg($tmp):false,'image/png'=>function_exists('imagecreatefrompng')?@imagecreatefrompng($tmp):false,'image/webp'=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($tmp):false,default=>false};
        if($source!==false&&function_exists('imagewebp')){
            $maxDim=2200;$w=imagesx($source);$h=imagesy($source);$scale=min(1,$maxDim/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
            $canvas=imagecreatetruecolor($nw,$nh);imagealphablending($canvas,false);imagesavealpha($canvas,true);$transparent=imagecolorallocatealpha($canvas,0,0,0,127);imagefilledrectangle($canvas,0,0,$nw,$nh,$transparent);imagecopyresampled($canvas,$source,0,0,0,0,$nw,$nh,$w,$h);
            $ok=imagewebp($canvas,$dest,84);imagedestroy($canvas);imagedestroy($source);if(!$ok)throw new \RuntimeException('Bildkonvertierung fehlgeschlagen.');$size=[$nw,$nh];
        }else{
            $relative=$userId.'/'.$kind.'-'.$id.'.'.$allowed[$mime];$dest=$root.'/'.$relative;$storedMime=$mime;
            if(!move_uploaded_file($tmp,$dest))throw new \RuntimeException('Bildspeicherung fehlgeschlagen.');
        }
        @chmod($dest,0660);$this->log->info('Private image stored',['user_id'=>$userId,'kind'=>$kind,'bytes'=>filesize($dest)?:0]);
        return ['path'=>$relative,'mime'=>$storedMime,'width'=>(int)$size[0],'height'=>(int)$size[1]];
    }

    public function absolute(string $relative): ?string
    {
        if(!preg_match('#^[0-9]+/[a-z]+-[a-f0-9]{32}\.(?:webp|jpg|png)$#',$relative))return null;
        $root=realpath((string)$this->config->get('uploads.dir',dirname(__DIR__,2).'/storage/uploads'));if($root===false)return null;
        $path=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
        return $path!==false&&is_file($path)&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)?$path:null;
    }
}
