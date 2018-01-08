<?php

/**
 * 使用 " php index.php --help " 查看帮助
 * */

ini_set('display_errors', 0);
error_reporting(0);

$User = array ( //配置使用者的用户名密码
    // UserName => PassWord ,
    'xxx' => 'xxx',
);

define( 'PHOTO_DIR', '/data/syncthing/Photos' ) ; //配置照片的目录
define( 'TMP_DIR'  , '/data/tmp/Photos' ) ;     //临时目录
define( 'PAGE_SIZE', 20 );  //每页展示图片数量

$PhotoExt = array ( 'jpg', 'jpeg', 'png' ); //照片扩展名,全小写

if ( PHP_SAPI == 'cli' ) {
    define('RUN_MODEL','cli');
    $Action = isset($_SERVER['argv']['1'] ) ? trim($_SERVER['argv']['1'] ) : '';
}else{
    define('RUN_MODEL','cgi');
    $Action = trim($_GET['act']);
}

if ( RUN_MODEL == 'cgi' ) {
    AccessControl($User);
}

$Photo = new Photo ( PHOTO_DIR, TMP_DIR, $PhotoExt );

switch ($Action) {
    case 'CheckEnv':
        $Photo->CheckEnv();
        break;
    case 'BuildIndex':
        $Photo->PhotoBuild();
        break;
    case 'BuildIndexForce':
        $Photo->PhotoBuild(true);
        break;
    case '--help':
    case '-h':
        HelpPage ();
        break;
    case 'ReadPhoto':
        $ImgType = trim($_GET['type']); //'thum';
        $ImgName = trim($_GET['name']); //ddf4d0ff7f6e686d6a47eb0ade8c7d3f.jpg
        $Photo->ReadPhoto( $ImgName );
        break;
    case 'ShowBig':
        ShowBig();
        break;
    default:
        if ( RUN_MODEL == 'cli') {
            HelpPage();
        }else{
            ShowPage($Photo);
        }
        break;
}

Class Photo {
    /**
     * $PhotoDir  照片保存目录
     * $CacheDir  临时目录
     * */
    function __construct ( $PhotoDir, $CacheDir, $PhotoExt ) {
        $this->PhotoDir = rtrim( $PhotoDir, '/\\' );
        $this->CacheDir = rtrim( $CacheDir, '/\\' );
        $this->PhotoExt = $PhotoExt;
        $this->ExifIndexFile = $this->CacheDir.'/ExifIndex';
        $this->ExifMonthIndexFile = $this->CacheDir.'/ExifMonthIndex';
        $this->ThumDir = $this->CacheDir;
    
    }

    /**
     * 检测运行环境
     * */
    public function CheckEnv ( ) {
        if ( version_compare (PHP_VERSION ,5.4, '<' ) ) {
            die ('This version of Photo requires at least PHP 5.4.0, currently running '. PHP_VERSION."\n");
        }
        $ExtArr = array ('SPL','imagick','mbstring');
        foreach ( $ExtArr as $val ) {
            if ( !extension_loaded($val) ) {
                die ("PHP extension \"".$val."\" not exists ! \n");
            }
        }
        if ( !file_exists($this->PhotoDir) ) {
            die('Directory '.$this->PhotoDir." not exists ! \n");
        }
        $Tmp = new SplFileInfo($this->PhotoDir);
        if ( !$Tmp->isReadable() ) {
            die('Directory '.$this->PhotoDir." is not readable ! \n");
        }
        if ( !file_exists($this->CacheDir) ) {
            die('Directory '.$this->CacheDir." not exists ! \n");
        }
        $Tmp = new SplFileInfo($this->CacheDir);
        if ( !$Tmp->isWritable() ) {
            die('Directory '.$this->CacheDir." is not writable ! \n");
        }
        echo "Success \n";
    }

    /**
     * 读取一个照片文件
     * $ImgName 索引中的文件名
     * */
    public function ReadPhoto ( $ImgName ) {
        if ( preg_match('/^(thum|big)_[0-9,a-f]{32}\.(.*)/', $ImgName, $matches ) && in_array( strtolower( $matches['2']), $this->PhotoExt ) ) {
            readfile( $this->ThumDir.'/'.$ImgName );
        }
        die(0);
    }

    /**
     * 创建图片索引
     * */
    public function PhotoBuild ( $Force = false ) {
        $PhotoArray = $this->MakeExifIndex();
        $this->Index2File ( $PhotoArray, $this->ExifIndexFile );
        $this->MonthIndex2File ( $PhotoArray, $this->ExifMonthIndexFile );
        $this->MakePhotoThum ( $PhotoArray, $this->ThumDir, $Force );
    }

    /**
     * 获取索引数据
     * $Page    第几页
     * $PageSize  获取的条数
     * */
    public function GetExifIndexData ( $Page, $PageSize ) {
        $Start = ( $Page - 1 ) * $PageSize;
        if ( !is_file($this->ExifIndexFile) ) {
            echo 'Index file '.$this->ExifIndexFile.' not exists ! Execute command ( php index.php BuildIndex )';
            exit;
        }
        try {
            $fp = new SplFileObject( $this->ExifIndexFile ); 
            $fp->seek( $Start );
            for ( $i = 0; $i < $PageSize; $i++ ) { 
                if ( !$fp->current() ) {
                    break;
                }
                $list = explode ( ' ', $fp->current() );
                $list['time'] = trim($list[0]);
                $list['big']  = trim($list[1]);
                $list['thum'] = trim($list[2]);
                $content[] = $list;
                $fp->next();
            }
            return $content ;
        } catch ( Exception $e ) {
            echo $e->getMessage();
            exit;
        }
    }

    /**
     * 获取月历索引数据
     * */
    public function GetExifMonthData(){
        try {
            $fp = new SplFileObject( $this->ExifMonthIndexFile ); 
            $fp->seek( 0 );
            while ( $fp->current() ) { 
                $list = explode ( ' ', $fp->current() );
                $list['month'] = trim($list[0]);
                $list['line']  = trim($list[1]);
                $content[] = $list;
                $fp->next();
            }
            return $content ;
        } catch ( Exception $e ) {
            echo $e->getMessage();
            exit;
        }
    }

    //初始化索引
    private function MakeExifIndex () {
        if ( PHP_SAPI == 'cli' ) {
            echo "Exif index start\n";
        }
        $PhotoArray = array ();
        $i = 1;
        $PhotoDirIterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->PhotoDir ) );
        foreach ($PhotoDirIterator as $FileSpl) {
            if ( $FileSpl->isFile () && in_array(strtolower( $FileSpl->getExtension () ), $this->PhotoExt) ) {
                $PhotoTime = $this-> PhotoTime ( $FileSpl->getPathName() );
                $PhotoFileName = str_replace ( $this->PhotoDir , '', $FileSpl->getPathName() );
                $PhotoArray[$PhotoTime.'.'.$i++] = array(
                    'PhotoFilePath'=> $FileSpl->getPathName(),
                    'BigFileName'  => 'big_'.md5($PhotoFileName).'.'.$FileSpl->getExtension (),
                    'ThumFileName' => 'thum_'.md5($PhotoFileName).'.'.$FileSpl->getExtension ()
                );
            }
        }
        krsort ( $PhotoArray );
        if ( PHP_SAPI == 'cli' ) {
            echo "Exif index end\n";
        }
        return $PhotoArray;
    }

    //索引写入文件
    private function Index2File ( $PhotoArray, $IndexFile ) {
        file_put_contents( $IndexFile ,'' ) ;
        foreach ( $PhotoArray as $key => $val ) {
            file_put_contents( $IndexFile, $key.' '.$val['BigFileName'].' '.$val['ThumFileName']."\n", FILE_APPEND ) ;
        }
    }

    //月历索引
    private function MonthIndex2File ( $PhotoArray, $IndexFile ) {
        file_put_contents( $IndexFile ,'' ) ;
        $Month = '';
        $i = 1;
        foreach ( $PhotoArray as $key => $val ) {
            $PicMonth = date ('Y-m',intval($key));
            if ( $Month != $PicMonth ) {
                file_put_contents( $IndexFile, $PicMonth.' '.$i."\n", FILE_APPEND ) ;
                $Month = $PicMonth;
            }
            $i ++ ;
        }
    }

    //生成缩略图
    private function MakePhotoThum ( $PhotoArray, $ThumDir, $Force = false ) {
        $thumb = new Imagick();
        $PicCount = count( $PhotoArray );
        $i = 1;
        foreach ( $PhotoArray as $key => $val ) {
            if (!is_file($ThumDir.'/'.$val['ThumFileName']) || !is_file($ThumDir.'/'.$val['BigFileName']) || $Force == true) {
                try{
                    $thumb->readImage( $val['PhotoFilePath'] );
                    $thumb->resizeImage( NULL, 240, Imagick::FILTER_LANCZOS, 1 );
                    $thumb->writeImage( $ThumDir.'/'.$val['ThumFileName'] );
                    $thumb->resizeImage( NULL, 1024, Imagick::FILTER_LANCZOS, 1 );
                    $thumb->writeImage( $ThumDir.'/'.$val['BigFileName'] );
                    $thumb->clear();
                } catch ( Exception $e ) {
                    echo $e->getMessage(),"\n";
                }
            }
            if ( PHP_SAPI == 'cli' ) {
                echo ProgressBar ( $i++ , $PicCount );
            }
        }
        $thumb->destroy(); 
    }

    /**
     * 获取照片的创建时间
     * $PhotoPath  图片的真实路径
     * */
    public function PhotoTime ( $PhotoPath ) {
        $ExifData = exif_read_data ( $PhotoPath, '', true );
        if ( isset( $ExifData['EXIF']['DateTimeOriginal'] ) ) {
            $PhotoTime = strtotime( $ExifData['EXIF']['DateTimeOriginal'] );
        } elseif ( isset( $ExifData['FILE']['FileDateTime'] )){
            $PhotoTime = $ExifData['FILE']['FileDateTime'];
        }else{
            $PhotoTime = filemtime($PhotoPath);
        }
        return $PhotoTime;
    }

}

/**
 * 权限访问
 * */
function AccessControl ( $User ) {
    if( ( !isset($User[$_SERVER['PHP_AUTH_USER']]) || $_SERVER['PHP_AUTH_PW'] != $User[$_SERVER['PHP_AUTH_USER']] ) || !$_SERVER['PHP_AUTH_USER'] ){ 
        header('WWW-Authenticate: Basic realm="Photo Auth"'); 
        header('HTTP/1.0 401 Unauthorized'); 
        die('Unauthorized');
    }
}

function MkListUrl ( $PageSize, $LastSelect='' ) {
    $LastSelect = $LastSelect ? intval($LastSelect) : intval($_GET['LastSelect']);
    return '/?Page='.$PageSize.'&LastSelect='.$LastSelect;
}

/**
 * 照片列表页
 * */
function ShowPage ( $Photo ) {
    $Page = intval($_GET['Page']) ? : 1;
    $PageSize = defined( 'PAGE_SIZE' ) ? PAGE_SIZE : 20;
    $PageData = $Photo->GetExifIndexData( $Page, $PageSize) ;

    if ($Page > 1) {
        $PrePage = $Page - 1;
        $PreUrl = MkListUrl($PrePage);
        $PreTitle = 'Pre Page';
    }else{
        $PreUrl = MkListUrl(1);
        $PreTitle = 'Home Page';
    }

    if (count($PageData) == $PageSize) {
        $NextPage = $Page+1;
        $NextUrl = MkListUrl( $NextPage );
        $NextTitle = 'Next Page';
    }else{
        $NextUrl = MkListUrl(1);
        $NextTitle = 'Home Page';
    }
    
    $PageMonthData = $Photo->GetExifMonthData();
    $MonthContent = '<select onchange="window.location=this.value;">';
    $MonthContent .= '<option value="'.MkListUrl(1).'">Home</option>';
    foreach ( $PageMonthData as $val ) {
        $MonthPage = ceil ( $val['line'] / $PageSize );
        $MonthPage = $MonthPage < 1 ? 1 : $MonthPage;
        $SelectContent = ($_GET['LastSelect'] == $MonthPage) ? ' selected="selected" ' : '';
        $MonthUrl = MkListUrl($MonthPage,$MonthPage);
        $MonthContent .= '<option value="'.$MonthUrl.'" '.$SelectContent.'>'.$val['month'].'</option>';
    }
    $MonthContent .= '</select>';

    echo <<<END
<html>
    <head>
        <title>Photos</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
        <style>
            body{
                font-size: 12px;
            }
            .PicPiece {
                padding: 5px;
                margin: 0 0 3px 0;
                -moz-page-break-inside: avoid;
                -webkit-column-break-inside: avoid;
                break-inside: avoid;
                border: 1px solid #CCC;
                border-radius: 10px;
            }
            .PicDiv img { 
                border-radius: 10px; 
                width: 100%;
                margin-bottom:5px;
            }
            .PicText { padding:5px; }
            .PicTextTime { }
            .PicTextOri { float:right; }
            a:link , a:visited , a:hover , a:active {color: #B0BEC5}
            .waterfall{
                -moz-column-count:2; /* Firefox */
                -webkit-column-count:2; /* Safari 和 Chrome */
                column-count:2;
                -moz-column-gap: 5px;
                -webkit-column-gap: 5px;
                column-gap: 5px;
            }
            button {
                background-color: #5cb9ac;
                border: none;
                color: white;
                padding: 5px 12px;
                text-align: center;
                text-decoration: none;
                display: inline-block;
                font-size: 16px;
                border-radius: 8px;
            }
            select {
                background-color: #5cb9ac;
                border-radius: 8px;
                padding: 5px;
                font-size: 16px;
                border: none;
                color: white;
                -webkit-appearance: none; /*for chrome*/
            }
        </style>
    </head>
    <body>
        <div style="vertical-align:middle;text-align:center;padding:10px">
            <a href="{$PreUrl}"><button>{$PreTitle}</button></a>
            {$MonthContent}
            <a href="{$NextUrl}"><button>{$NextTitle}</button></a>
        </div>

        <div class="container">
            <div class="waterfall">
END;

    foreach ( $PageData as $val ) {
        $DateTime = date ('Y年m月d日', $val['time']);
        echo '
            <div class="PicPiece">
                <div class="PicDiv"><img src="/index.php?act=ReadPhoto&type=thum&name='.$val['thum'].'" /></div>
                <hr/>
                <div class="PicText">
                    <span class="PicTextTime">时间: '.$DateTime.'</span>
                    <span class="PicTextOri"><a href="/index.php?act=ShowBig&name='.$val['big'].'" target="_blank">大图</a></span>
                </div>
            </div>
        ';
    }

echo <<<END
            </div>
        </div>
        <div style="vertical-align:middle;text-align:center;padding:10px">
            <a href="{$PreUrl}"><button class="button">{$PreTitle}</button></a>
            {$MonthContent}
            <a href="{$NextUrl}"><button class="button">{$NextTitle}</button></a>
        </div>
    </body>
</html>
END;

}

/**
 * 大图页面
 * */
function ShowBig (){
    $name = $_GET['name'];
    echo <<<END
<html>
    <head>
        <title>Photos</title>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, height=device-height, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
        <style>
            .PicShow { width:100%; text-align:center;}
            .PicShow img { width:100%; }
            .button {
                background-color: #5cb9ac;
                border: none;
                color: white;
                padding: 5px 32px;
                text-align: center;
                text-decoration: none;
                display: inline-block;
                font-size: 16px;
                border-radius: 12px;
            }
        </style>
    </head>
    <body>
        <div class="PicShow">
            <img src="/index.php?act=ReadPhoto&name={$name}" />
        </div>
        <div style="vertical-align:middle;text-align:center;padding:10px">
            <a href="javascript:window.opener=null;window.open('','_self');window.close();"><button class="button">Close</button></a>
        </div>
    </body>
</html>
END;

}

#进度查询
function ProgressBar($Now ,$Max){
    $JDMax=100;
    $JDTiao = '>';
    $JDTNull = '-';
    static $strLen = 0;
    $JDb = round ( $Now / $Max , 2 ) * $JDMax;
    $Tiao = '[' . str_repeat ( $JDTiao ,$JDb) . str_repeat ( $JDTNull , $JDMax - $JDb) . ']';
    $Tiao .='('. $JDb . '%) ('.$Now.'/'.$Max.')';
    $Return = str_repeat (chr(8) , $strLen ) . $Tiao;
    $strLen = strlen($Tiao);
    return $Return;
}

#帮助页面
function HelpPage () {
echo <<<EOF
 * |- 1. 检测运行环境.
 *   |- php index.php CheckEnv 
 *
 * |- 2. 增量创建索引(适合图片新增).
 *   |- php index.php BuildIndex  
 *
 * |- 3. 强制创建索引,不使用图片缓存(适合图片修改后创建索引).
 *   |- php index.php BuildIndexForce 
 *
 * |- 4. 授权用户访问.
 *   |- 修改 \$User 数组变量,配置可使的用户名密码
 *
 * |- 5. 访问相册.
 *   |- 将本文件部署到WebService然后访问项目域名即可 
 \n
EOF;

}
