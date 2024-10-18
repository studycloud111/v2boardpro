<?php
// ┌───────────────────────────────────────────────────────────────────────────────────────────────────────┐ \\
// │ Moetor                                                                                                | \\
// |                                                                                                       | \\
// │ 注意:保存后在项目根目录执行：php artisan config:cache 才会生效                                        | \\
// ├───────────────────────────────────────────────────────────────────────────────────────────────────────┤ \\
// │ Copyright © 2024 (https://t.me/Corcol)                                                            │ \\
// └───────────────────────────────────────────────────────────────────────────────────────────────────────┘ \\
return [
    
    # (必填项) 授权密钥
    "license" => "",
    
    # Crisp在线客服，不填写则入口隐藏
    "crispId" => "05099661-d4fa-468a-bdf8-f71136e82eba",
    
    # 邀请链接，APP里点复制链接，邀请码会拼接在最后
    "inviteUrl" => "https://xn--yfrz36isthva.com/#/register?code=",
    
    # 是否显示流量明细入口 true=显示，false=隐藏
    "trafficLogShow" => true,
    
    # 版本更新时是否强制，最新版本信息请在面板-系统配置-APP-Android进行配置，下载地址需要apk文件直链url
    "versionUpdateForce" => false,
    
    # 版本更新是否跳转：true=跳转到下载地址（在面板里配置），false=app应用内更新直接下载安装（面板里的下载地址需要直链）
    "versionUpdateJump" => false,
    
    # 节点延迟的展示相关
    "nodeDelayShow" => [
        "type" => 1,            # 展示方式：0=延迟数值，1=信号图标
        "colorBest" => 1500,    # 延迟数值/图标颜色：延迟小于此值为绿色
        "colorGood" => 2500,    # 延迟数值/图标颜色：延迟小于此值并大于{colorBest}为黄色，大于此值为红色
    ],
    
    # 无限流量的展示条件及文本
    "trafficUnlimited" => [
        "value" => 99999,             # 套餐流量为此值时展示（GB），填0或不填时不启用
        "text" => "无限制",       # 在app中的展示文本
    ],
    
    # 用户协议及隐私政策
    "agreements" => [
        "show" => true,         # 是否显示（总开关：[第一次进入app、登录、注册、其他设置-关于]的显示）
        "title" => "个人信息保护提示",      # 第一次进入 app 的弹窗标题
        # 第一次进入 app 的弹窗内容，支持文字a标签跳转
        "content" => "感谢您使用萌通加速！我们将依据<a href='https://xn--yfrz36isthva.com/user-agreement.html'>《用户服务协议》</a>和<a href='https://xn--yfrz36isthva.com/privacy-policy.html'>《隐私政策》</a>来帮助您了解我们在收集、使用、存储和共享您个人信息的情况以及您享有的相关权利。<br><br>1、您可以通过查看《用户服务协议》和《隐私政策》来了解我们可能收集、使用的您的个人信息情况；<br><br>2、基于您的明示授权，我们可能调用您的重要设备权限。我们将在首次调用时逐项询问您是否允许使用该权限，您有权拒绝或取消授权；<br><br>3、我们会采取业界先进的安全措施保护您的信息安全；<br><br>4、您可以查询、更正、删除、撤回授权您的个人信息，我们也提供账户注销的渠道。<br><br>",
         # 服务协议url
        "serviceLink" => "https://xn--yfrz36isthva.com/user-agreement.html",
        # 隐私政策url
        "privacyLink" => "https://xn--yfrz36isthva.com/privacy-policy.html",
    ],
    
    # 每次进入app时的弹窗，支持多弹窗队列弹出
    "noticeList" => [
        [
            "show" => true,  # 开关
            "title" => "邀请返利20%",
            "content" => "每邀请一名朋友并成为我们的会员，您将获得邀请佣金奖励(佣金比例20%)，若朋友在萌通加速消费100元，您则可获得返利20元。此返利可用于购买套餐或提现！【快来和我们一起赚钱吧！】",
            "negative" => "",  # 左边按钮文字，不填则隐藏
            "position" => "",  # 右边按钮文字，不填则隐藏
            "positionLink" => ""  # 右边按钮跳转地址，不填则不进行跳转
        ],
        [
            "show" => false,
            "title" => "示例",
            "content" => "这是第二个弹窗，这里是弹窗内容",
            "negative" => "",
            "position" => "",
            "positionLink" => ""
        ],
    ],
    
    # 购买套餐下单时的弹窗
    "buyTip" => [
        "show" => false,
        "title" => "购买须知",
        "content" => "无退款服务，是否确认购买？",
    ],
    
    # 首页网站推荐
    "homeNav" => [
        "show" => true,  # 是否显示
        "title" => "网站推荐",  # 标题
        # 以下列表数量不限可以无限添加，但请注意格式
        "list" => [
            [
                "text" => "萌通官网",
                "icon" => "https://i3.mjj.rip/2023/07/10/c3e61eb2c26557451924b69516a88a11.png",
                "link" => "https://xn--yfrz36isthva.com",
            ],
            [
                "text" => "Google",
                "icon" => "https://i3.mjj.rip/2023/07/10/46daf515c691dffc8be5389efa01b215.webp",
                "link" => "https://www.google.com",
            ],
            [
                "text" => "Telegram",
                "icon" => "https://simg.doyo.cn/imgfile/bgame/202303/08094239yadd.jpg",
                "link" => "https://t.me/moetors",
            ],
            [
                "text" => "ChatGPT",
                "icon" => "https://cdnjson.com/images/2023/07/11/ChatGPT_logo.svg.png",
                "link" => "https://openai.com",
            ],
            [
                "text" => "Facebook",
                "icon" => "https://simg.doyo.cn/imgfile/bgame/202303/07161609vgut.jpg",
                "link" => "https://www.facebook.com",
            ],
            [
                "text" => "Instagram",
                "icon" => "https://simg.doyo.cn/imgfile/bgame/202303/07154226kh8v.jpg",
                "link" => "https://www.instagram.com",
            ],
            [
                "text" => "Spotify",
                "icon" => "https://i3.mjj.rip/2023/07/10/c0e2fa09778c0a0864966f4ad16f5f7d.webp",
                "link" => "https://www.spotify.com",
            ],
            [
                "text" => "YouTube",
                "icon" => "https://simg.doyo.cn/imgfile/bgame/202303/04165047scdv.jpg",
                "link" => "https://www.youtube.com",
            ],
            [
                "text" => "Netflix",
                "icon" => "https://cdnjson.com/images/2023/07/11/e07a41e8afc91b3ff66ddd02e6b8378e786034721acfa948e43de85449c7971b_200.png",
                "link" => "https://www.netflix.com",
            ],
            [
                "text" => "Disney+",
                "icon" => "https://cdnjson.com/images/2023/07/11/eb7202d9c9bfbc97c6f1e644dce1f58f9fbcf193ae9edff9bdda2c088cdbabf0_200.png",
                "link" => "https://www.disneyplus.com",
            ],
            [
                "text" => "泥视频",
                "icon" => "https://cdnjson.com/images/2023/07/11/icon.webp",
                "link" => "https://www.nivod4.tv/?utm_source=JadeDuck",
            ],
            [
                "text" => "18+",
                "icon" => "https://cdnjson.com/images/2023/07/11/depositphotos_68110071-stock-illustration-18-age-restriction-sign.webp",
                "link" => "https://theporndude.com/zh",
            ],
        ]
    ],
];
