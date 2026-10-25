<!DOCTYPE html>
<html>
<head>
<title>ckplayer播放器</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<meta http-equiv="X-UA-Compatible" content="IE=11" />
<meta content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no" id="viewport" name="viewport">
	<style type="text/css">
	html,body{width:100%;height:100%; padding:0; margin:0;}
	#playerCnt{width:100%;height:100%;position:relative;}
	</style>
<link rel="stylesheet" type="text/css" href="/static/player/ckplayer/css/ckplayer.css">
<script type="text/javascript" charset="utf-8" src="/static/player/ckplayer/flv.js/flv.js"></script>
<script type="text/javascript" charset="utf-8" src="/static/player/ckplayer/hls.js/hls.js"></script>
<script type="text/javascript" charset="utf-8" src="/static/player/ckplayer/js/ckplayer.js"></script>
<script type="text/javascript" charset="utf-8" src="/static/player/ckplayer/language/zh.cn.js"></script>
<script type="text/javascript" charset="utf-8" src="/static/player/ckplayer/mpegts.js/mpegts.js"></script>
</head>
<body marginwidth="0" marginheight="0">
<div id="playerCnt" class="playerCnt"></div>
    <script type="text/javascript">
        var plug="normal";
        var live=false;
        var url=parent.MacPlayer.PlayUrl;
        var urlhz=url.split(".").pop().toLowerCase();
        if(urlhz="m3u8"){plug="hls.js";}else if(urlhz="flv"){plug="flv.js";}else if(urlhz="ts"){plug="mpegts.js";}else if(urlhz="mp4"){plug="";}else{plug="";}
        var videoObject = {
                                container:".playerCnt", //容器的ID或className
								autoplay:true, //是否自动播放
								seek:'cookie',//指定跳转到cookie记录的时间，使用该属性必需配置属性cookie
								    cookie:'abcdefg',//cookie名称,请在同一域中保持唯一
                                //rightBar:false, //是否开启右边控制栏
                                //screenshot:false, //截图功能是否开启
                                smallWindows:true, //是否启用小窗口模式
								smallWindowsDrag:true,//当处于小窗口模式时是否可拖动播放器
                                playbackrateOpen:false, //是否开启控制栏倍速选项
								playbackrateList:[0.75,1,1.25,1.5,2,4],//倍速配置值
                                webFull:true, //页面全屏按钮事件
								//theatre:false,//剧场模式
								mouseWheelVolume:2,//是否启用鼠标滚轮调节音量功能，0=不启用，1=启用，2=全屏时才启用
                                plug:plug, //使用插件
                                live:false, //是否是直播								
								timeScheduleAdjust:1,//是否可调节播放进度,0不启用，1是启用，2是只能前进（向右拖动），3是只能后退，4是只能前进但能回到第一次拖动时的位置，5是看过的地方可以随意拖动
								controls:false,//是否显示自带控制栏
								rightBar:false,//是否开启右边控制栏
								language:'zh.cn',//语言包文件
//								next: true,//下一集按钮动作
//                              next:{
//                                    link:parent.MacPlayer.PlayLinkNext,
//                                    content:".video-next"
//                               }, //下一集按钮动作
                                menu:[ //右键菜单
                                        {
                                                title:"ckplayer",
                                                link:"http://www.ckplayer.com"                
                                        },
                                        {
                                                title:"version:X3",
                                                underline:true
                                        },
                                        {
                                                title:"关于视频",
                                                click:"aboutShow"
                                        }
                                ],
                                information:{ //关于
                                        "视频加载：":"{loadTime} 秒",
                                        "视频时长：":"{duration} 秒",
                                        "视频尺寸：":"{videoWidth}x{videoHeight}",
                                        "视频音量：":"{volume}%",
                                        "视频FPS：":"{fps} fps",
                                        "音频解码：":"{audioDecodedByteCount} Byte",
                                        "视频解码：":"{videoDecodedByteCount} Byte"
                                },
                                video:url 
                        };
        var player=new ckplayer(videoObject)						
    </script>
</body>
</html>