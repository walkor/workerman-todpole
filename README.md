workerman-todpole
=================

蝌蚪游泳交互程序 使用PHP（workerman框架）+HTML5开发

[线上DEMO](http://kedou.workerman.net)

在自己的服务器上(云主机、vps、物理主机等)安装部署
==================

## Linux 系统

1、下载或者clone代码到本地 详细安装教程见 [www.workerman.net/workerman-todpole](http://www.workerman.net/workerman-todpole)

2、进入目录运行 php start.php start -d

3、浏览器访问地址  http://ip:8383 （ip为服务器ip）如图：（如果无法打开页面请尝试关闭服务器防火墙）

![小蝌蚪游戏截图](https://github.com/walkor/workerman-todpole/blob/master/Applications/Todpole/Web/images/workerman-todpole-browser.png?raw=true)

## Windows系统
（windows系统仅作为开发测试环境）
首先windows系统需要先下载windows版本workerman，替换Workerman目录。

步骤：
1、删除Workerman目录包括文件
2、下载或者clone windows版本workerman，zip地址 https://github.com/walkor/workerman-for-win/archive/master.zip
3、解压到原Worekrman目录所在位置，同时目录workerman-for-win-master重命名为Workerman(注意第一个字母W为大写)
4、双击start_for_win.bat启动（系统已经装好php，并设置好环境变量，要求版本php>=5.3.3）
5、浏览器访问地址  http://127.0.0.1:8383 

虚拟空间（静态空间、php、jsp、asp等）安装部署
==================
虚拟空间安装请使用这个包 [网页空间版本](https://github.com/walkor/workerman-todpole-web)

非常感谢Rumpetroll
===================
本程序是由 [Rumpetroll](http://rumpetroll.com) 修改而来，主要是后台由ruby改成了php。非常感谢Rumpetroll出色的工作。  
原 [Repo: https://github.com/danielmahal/Rumpetroll](https://github.com/danielmahal/Rumpetroll)



