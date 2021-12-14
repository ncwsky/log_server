##使用
```
composer create-project  myphps/log_server ./log_server dev-master
```
~~~~
复制client.conf.example.php 为 client.conf.php，并修改配置
cp client.conf.example.php client.conf.php

./Client.php 启动

可开启sqlite支持方便接口统计 需要pdo-sqlite支持
