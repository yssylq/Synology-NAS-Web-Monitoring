# Synology-NAS-Web-Monitoring
群晖NAS网页监控

## 系统介绍
Synology-NAS-Web-Monitoring是一个简约风格的群晖NAS网页监控，使用群晖系统自带的WebAPI，无需安装任何插件或者拉取Docker，所有数据直接从群晖系统读取，使用PHP+MySQL编写。

## 数据库结构
- `pc` 表：存储基础信息，包括登录账号密码，群晖内网链接等等
- `log` 表：记录登录用户信息

## 安装使用
1. 初次使用需要将 `nas_monitor.sql` 文件导入群晖MariaDB数据库
2. 在 `pc` 表修改数据库配置信息，群晖登录账号密码，群晖内网链接
3. 群晖登录账号不要开启2FA状态，可以新建一个新用户专用用于此系统

## 系统要求
1. 适配环境：DSM7.2，PHP8.2，MariaDB10（可以直接搭建在群晖的Web Station里）
2. 需要导入数据库文件并修改部分信息
3. 如遇问题请发邮件：admin@tzele.me
   
## 使用条例
- 允许二次修改
- 二改需注明原作者
- 禁止商业倒卖
  
## 开源许可证
本项目采用 MIT 许可证开源。
在原作者的设计上加了后台管理，原作者不支持提交文件，如果需要合作，请联系原作者

## 系统截图
登录页面
<img width="1905" height="848" alt="image" src="https://github.com/user-attachments/assets/f7c3c554-02fa-4aa8-aded-1af68cb440bf" />
监控页面
<img width="1920" height="858" alt="image" src="https://github.com/user-attachments/assets/523eabe7-80dd-4567-a486-786868d4b022" />
