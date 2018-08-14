// 此文件下载者不用更改，兼容其他域名使用
var Settings = function() {
	// 如果是workerman.net phpgame.cn域名 则采用多个接入端随机负载均衡
	var domain_arr = ['workerman.net', 'www.workerman.net'];
	if(0 <= $.inArray(document.domain, domain_arr))
	{
		this.socketServer = 'ws://'+domain_arr[Math.floor(Math.random() * domain_arr.length + 1)-1]+':8282';
	}
	else
	{
		// 运行在其它域名上
		if("https:" == document.location.protocol){
			this.socketServer = 'wss://'+document.domain+':8283';
		}else{
			this.socketServer = 'ws://'+document.domain+':8282';
		}
	}
}
