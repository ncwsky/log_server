##使用
```
composer create-project  myphps/log_server ./log_server dev-master
```
~~~~
复制client.conf.example.php 为 client.conf.php，并修改配置
cp client.conf.example.php client.conf.php

./Client.php 启动

可开启sqlite支持方便接口统计 需要pdo-sqlite支持
~~~~

TODO

>远程操作自更新(self-update)、重载(reload)、重启(restart) 

>Server可做为中继节点（处理数据）转发数据到另一节点

>数据采集处理