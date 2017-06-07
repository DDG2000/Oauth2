<?php
namespace app\www\controller;

class Oauth extends BaseController {

    protected $plat=[
        'qq'=>'QQ',
        'tencent'=>'腾讯微博',
        'weixin'=>'微信',
        'sina'=>'微博',
    ];
    public function __construct() {
        parent::__construct();

    }

    /**
     * 第三方登录地址 入口 www.lightlamppc.com/oauth/login?type=qq
     * @param null $type
     */
    public function login($type = null){

        empty($type) && $this->error('参数错误');
        session('login_http_referer',isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : '');
        import('ThinkSDK.ThinkOauth', EXTEND_PATH, '.class.php');
        //加载ThinkOauth类并实例化一个对象
        $sns  = \ThinkOauth::getInstance($type);
        //跳转到授权页面
        $this->redirect($sns->getRequestCodeURL());
    }

    /**
     * 第三方登录授权回调地址
     * @param null $type
     * @param null $code
     */
    public function callback($type = null, $code = null){
        (empty($type)) && $this->error('参数错误');
        //记录日志 返回参数
        if(empty($code)){
// 			redirect(__ROOT__."/");
            $this->redirect("/");
        }
        import('ThinkSDK.ThinkOauth', EXTEND_PATH, '.class.php');
        //加载ThinkOauth类并实例化一个对象
        $sns  = \ThinkOauth::getInstance($type);
        //腾讯微博需传递的额外参数
        $extend = null;
        if($type == 'tencent'){
            $extend = array('openid' => input("get.openid"), 'openkey' => input("get.openkey"));
        }
        //请妥善保管这里获取到的Token信息，方便以后API调用
        //调用方法，实例化SDK对象的时候直接作为构造函数的第二个参数传入
        //如： $qq = ThinkOauth::getInstance('qq', $token);
        //获取access_token 相关参数
        //微博返回  $token=["access_token": "ACCESS_TOKEN","expires_in": 1234,"remind_in":"798114","uid":"12341234"]
        //qq返回  $token=["access_token": "ACCESS_TOKEN","expires_in": "7776000","refresh_token":"REFRESH_TOKEN"]
        //微信返回  $token=["access_token": "ACCESS_TOKEN","expires_in": "7776000","refresh_token":"REFRESH_TOKEN","openid":"OPENID","scope":"SCOPE"]
        $token = $sns->getAccessToken($code , $extend);
        //获取当前登录用户信息
        if(is_array($token)){
            $user_info = controller('Type', 'event')->$type($token);
            $session_oauth_bang=session('oauth_bang');
            if(!empty($session_oauth_bang)){
                $this->_bang_handle($user_info, $type, $token);
            }else{
                $this->_login_handle($user_info, $type, $token);
            }
        }else{
            $this->success('登录失败！',$this->_get_login_redirect());
        }
    }

    /**
     * 第三方账号绑定  入口
     * @param string $type
     */
    public function bang($type=""){
        if(sp_is_user_login()){
            empty($type) && $this->error('参数错误');
            //加载ThinkOauth类并实例化一个对象
            import('ThinkSDK.ThinkOauth', EXTEND_PATH, '.class.php');
            $sns  = \ThinkOauth::getInstance($type);
            //跳转到授权页面
            session('oauth_bang',1);
            $this->redirect($sns->getRequestCodeURL());
        }else{
            $this->error("您还没有登录！");
        }
    }

    /**
     * 获取登录跳转地址
     */
    private function _get_login_redirect(){
        $session_login_http_referer=session('login_http_referer');
// 		return empty($session_login_http_referer)?__ROOT__."/":$session_login_http_referer;
        return empty($session_login_http_referer)?"/":$session_login_http_referer;
    }

    /**
     * 绑定第三方账号
     * @param $user_info
     * @param $type
     * @param $token
     */
    private function _bang_handle($user_info, $type, $token){
        $current_uid=sp_get_current_userid();
        $type=strtolower($type);

        //todo 检查第三方是否绑定
//		if($find_oauth_user){
//			if($find_oauth_user['uid']==$current_uid){
//				$this->error("您之前已经绑定过此账号！",url('profile/edit'));exit;
//			}else{
//				$this->error("该帐号已被本站其他账号绑定！",url('profile/edit'));exit;
//			}
//		}
        //微博返回  $token=["access_token": "ACCESS_TOKEN","expires_in": 1234,"remind_in":"798114","uid":"12341234"]
        //qq返回  $token=["access_token": "ACCESS_TOKEN","expires_in": "7776000","refresh_token":"REFRESH_TOKEN"]
        //微信返回  $token=["access_token": "ACCESS_TOKEN","expires_in": "7776000","refresh_token":"REFRESH_TOKEN","openid":"OPENID","scope":"SCOPE"]
        if($current_uid){
            //第三方用户表中创建数据
            $new_oauth_user_data = array(
                'accessToken' => $token['access_token'],
                'userId' => $current_uid,//用户ID
                'loginType' => $user_info['type'],
                'platformName' => $this->plat[$type],
                'userName' => $user_info['name'],
                'usid' => isset($token['uid'])?$token['uid']:'',//用户ID 只有微博中有
                'iconURL' => $user_info['head'],
                'openId' => isset($token['openid'])?$token['openid']:'',//微信有
                'token' => '',
                'expirationDate' => time()+$token['expires_in'],//	过期时间
                'unionId' => isset($token['openid'])?$token['openid']:'',//微信只有openid
                'refreshToken' => isset($token['refresh_token'])?$token['refresh_token']:'',//qq 微信有
            );
            //绑定第三方数据
            $user = controller('User','event')->gwsoftUserBusiBindAccount($new_oauth_user_data);
            if($user){
                $uid = $user['userId'] ;
                session(SESSION_USER_ID,$uid) ;
                session(SESSION_USER_INFO,$user) ;
                $this->success("绑定成功！",url('profile/edit'));
            }else{
                $this->error("绑定失败！",url('profile/edit'));
            }
        }else{
            $this->error("绑定失败！",url('profile/edit'));
        }
    }

    /**
     * 处理第三方登陆
     * @param $user_info
     * @param $type
     * @param $token
     */
    private function _login_handle($user_info, $type, $token){
        $type=strtolower($type);
        $new_oauth_user_data = array(
            'loginType' => $user_info['type'],
            'platformName' => $this->plat[$type],
            'userName' => $user_info['name'],
            'usid' => isset($token['uid'])?$token['uid']:'',//用户ID
            'iconURL' => $user_info['head'],
            'accessToken' => $token['access_token'],
            'openId' => isset($token['openid'])?$token['openid']:'',//微信有
            'expirationDate' => time()+$token['expires_in'],
            'unionId' => isset($token['openid'])?$token['openid']:'',//微信有
            'refreshToken' => isset($token['refresh_token'])?$token['refresh_token']:'',//qq 微信有
        );
        $user = controller('User','event')->gwsoftUserBusiThreepartLogin($new_oauth_user_data);
        if($user){
            $uid = $user['userId'] ;
            session(SESSION_USER_ID,$uid) ;
            session(SESSION_USER_INFO,$user) ;
            $this->redirect($this->_get_login_redirect());
        }else{
            $this->success('登录失败！',$this->_get_login_redirect());
        }
    }
}
