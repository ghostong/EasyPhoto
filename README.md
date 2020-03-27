lit Photo 相册
====================

### 写在前面
    - 一个简单的私人相册项目, 部署简单, 支持缩略图预览.
	- 安装简单, 使用简单.
	
### 安装
1. 运行环境
```php
    1. PHP 5.4 或更新的版本.
    2. imagemagick 6.0 或更新的版本.
    3. PHP 扩展 : SPL, imagick, mbstring .
```

2. 下载代码
```php
    git clone git@github.com:ghostong/EasyPhoto.git
```

3. 配置项目
```php
    1. 打开文件 vim photo/index.php .
	2. 修改 PHOTO_DIR, TMP_DIR, PAGE_SIZE 三个常量.
	3. 三个常量分别代表, 照片的目录(PHOTO_DIR), 临时目录(TMP_DIR), 每页展示图片数量(PAGE_SIZE).
```

4. 开始使用
```php
    1. 检测运行环境.
        |- php index.php CheckEnv
 
    2. 增量创建索引(适合图片新增).
        |- php index.php BuildIndex

    3. 强制创建索引,不使用图片缓存(适合图片修改后创建索引).
        |- php index.php BuildIndexForce
    
    4. 授权用户访问
        |- 修改 $User 数组变量,配置可使的用户名密码.
 
    5. 访问相册
        |- 将本文件部署到WebService然后访问项目域名即可.
		|- 具体WebService配置将不再详细赘述.
```
