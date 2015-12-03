<?php
namespace Toplan\Sms;

use Toplan\PhpSms\Sms;
use \Validator;
class SmsManager
{
    /**
     * sent info
     * @var
     */
    protected $sentInfo;

    /**
     * storage
     * @var
     */
    protected static $storage;

    /**
     * construct
     */
	public function __construct()
    {
        $this->init();
    }

    /**
     * sms manager init
     */
    private function init()
    {
        $this->sentInfo = [
                'sent' => false,
                'mobile' => '',
                'code' => '',
                'deadline_time' => 0,
                'verify' => config('laravel-sms.verify', []),
            ];
    }

    /**
     * get sent info
     * @return mixed
     */
    public function getSentInfo()
    {
        return $this->sentInfo;
    }

    /**
     * set sent data
     * @param array $key
     * @param null $data
     */
    public function setSentInfo($key, $data = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->setSentInfo($k, $v);
            }
        } elseif (array_key_exists("$key", $this->sentInfo)) {
            $this->sentInfo["$key"] = $data;
        }
    }

    /**
     * get storage
     * @return null
     * @throws LaravelSmsException
     */
    public function storage()
    {
        if (self::$storage) {
            return self::$storage;
        }
        $className = config('laravel-sms.storage', 'Toplan\Sms\SessionStorage');
        if (class_exists($className)) {
            self::$storage = new $className();
            return self::$storage;
        }
        throw new LaravelSmsException("Generator storage failed, don`t find class [$className]");
    }

    /**
     * put sms sent info to storage
     * @param       $uuid
     * @param array $data
     *
     * @throws LaravelSmsException
     */
    public function storeSentInfo($uuid, $data = [])
    {
        if (is_array($uuid)) {
            $data = $uuid;
            $uuid = null;
        }
        if (is_array($data)) {
            $this->setSentInfo($data);
        }
        $key = $this->getStoreKey($uuid);
        $this->storage()->set($key, $this->getSentInfo());
    }

    /**
     * get sms sent info from storage
     * @param  $uuid
     * @return mixed
     */
    public function getSentInfoFromStorage($uuid = null)
    {
        $key = $this->getStoreKey($uuid);
        return $this->storage()->get($key, []);
    }

    /**
     * remove sms data from session
     * @param  $uuid
     */
    public function forgetSentInfoFromStorage($uuid = null)
    {
        $key = $this->getStoreKey($uuid);
        $this->storage()->forget($key);
    }

    /**
     * get store key
     * support split-> . : + * /
     * @return mixed
     */
    public function getStoreKey()
    {
        $prefix = config('laravel-sms.storePrefixKey', 'laravel_sms_info');
        $args = func_get_args();
        $split = '.';
        $appends = [];
        foreach ($args as $arg) {
            $arg = (String) $arg;
            if ($arg) {
                if (preg_match('/^[.:\+\*\/]+$/', $arg)) {
                    $split = $arg;
                } elseif(preg_match('/^[_\-0-9a-zA-Z]+$/', $arg)) {
                    array_push($appends, $arg);
                }
            }
        }
        if ($appends) {
            $prefix .= $split . implode($split, $appends);
        }
        return $prefix;
    }

    /**
     * get verify config
     * @param $name
     *
     * @return mixed
     * @throws LaravelSmsException
     */
    protected function getVerifyData($name)
    {
        if (!$name) {
            return $this->sentInfo['verify'];
        }
        if ($this->sentInfo['verify']["$name"]) {
            return $this->sentInfo['verify']["$name"];
        }
        throw new LaravelSmsException("Don`t find [$name] verify data in config file:laravel-sms.php");
    }

    /**
     * whether contain a character validation rule
     * @param $name
     * @param $ruleName
     *
     * @return bool
     */
    public function hasRule($name, $ruleName)
    {
        $data = $this->getVerifyData($name);
        return isset($data['rules']["$ruleName"]);
    }

    /**
     * get rule by name
     * @param $name
     *
     * @return mixed
     */
    public function getRule($name)
    {
        $data = $this->getVerifyData($name);
        $ruleName = $data['use'];
        if (array_key_exists($ruleName, $data['rules'])) {
            return $data['rules']["$ruleName"];
        }
        return $ruleName;
    }

    /**
     * get used rule`s alias
     * @param $name
     *
     * @return mixed
     * @throws LaravelSmsException
     */
    public function getUsedRuleAlias($name)
    {
        $data = $this->getVerifyData($name);
        return $data['use'];
    }

    /**
     * manual set verify rule
     * @param $name
     * @param $value
     *
     * @return mixed
     */
    public function useRule($name, $value)
    {
        if ($this->getVerifyData($name)) {
            $this->sentInfo['verify']["$name"]['use'] = $value;
        }
    }

    /**
     * whether to verify character data
     * @param string $name
     *
     * @return mixed
     */
    public function isCheck($name = 'mobile')
    {
        $data = $this->getVerifyData($name);
        return !!$data['enable'];
    }

    /**
     * get verify sms templates id
     * @return array
     */
    public function getVerifySmsTemplates()
    {
        $templates = [];
        $enableAgents = Sms::getEnableAgents();
        $agentsConfig = Sms::getAgentsConfig();
        foreach ($enableAgents as $name => $opts) {
            if (isset($agentsConfig["$name"])) {
                if (isset($agentsConfig["$name"]['verifySmsTemplateId'])) {
                    array_push($templates, [
                        $name => $agentsConfig["$name"]['verifySmsTemplateId']
                    ]);
                }
            }
        }
        return $templates;
    }

    /**
     * get verify sms content
     * @return mixed
     */
    public function getVerifySmsContent()
    {
        return config('laravel-sms.verifySmsContent');
    }

    /**
     * generate verify code
     * @param null $length
     * @param null $characters
     *
     * @return string
     */
    public function generateCode($length = null, $characters = null)
    {
        $length = $length ?: (int) config('laravel-sms.codeLength');
        $characters = $characters ?: '0123456789';
        $charLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; ++$i) {
            $randomString .= $characters[mt_rand(0, $charLength - 1)];
        }
        return $randomString;
    }

    /**
     * get code valid time (minutes)
     * @return mixed
     */
    public function getCodeValidTime()
    {
        return config('laravel-sms.codeValidTime');
    }

    /**
     * 设置可以发送短信的时间
     * @param int $uuid
     * @param int $seconds
     *
     * @return int
     */
    public function storeCanSendTime($uuid, $seconds = 60)
    {
        $key = $this->getStoreKey($uuid, 'canSendTime');
        $time = time() + $seconds;
        $this->storage()->set($key, $time);
        return $time;
    }

    /**
     * 获取可以发送短信的时间
     * @param int $uuid
     * @return mixed
     */
    protected function getCanSendTimeFromStorage($uuid = null)
    {
        $key = $this->getStoreKey($uuid, 'canSendTime');
        return $this->storage()->get($key, 0);
    }

    /**
     * 判断能否发送
     * @param  $uuid
     * @return bool
     */
    public function canSend($uuid = null)
    {
        return $this->getCanSendTimeFromStorage($uuid) <= time();
    }

    /**
     * validator
     * @param array  $input
     * @param string $rule
     *
     * @return array
     */
    public function validator(Array $input, $rule = '')
    {
        if (!$input) {
            return $this->genResult(false, 'no_input_value');
        }
        $uuid = isset($input['uuid']) ? $input['uuid'] : null;
        if (!$this->canSend($uuid)) {
            $seconds = $input['seconds'];
            return $this->genResult(false, 'request_invalid', [$seconds]);
        }
        if ($this->isCheck('mobile')) {
            if ($this->hasRule('mobile', $rule)) {
                $this->useRule('mobile', $rule);
            }
            $realRule = $this->getRule('mobile');
            $validator = Validator::make($input, [
                'mobile' => $realRule
            ]);
            if ($validator->fails()) {
                $msg = $validator->errors()->first();
                $rule = $this->getUsedRuleAlias('mobile');
                return $this->genResult(false, $rule, $msg);
            }
        }
        return $this->genResult(true, 'success');
    }

    /**
     * generator validator result
     * @param        $pass
     * @param        $type
     * @param string $message
     * @param Array  $data
     *
     * @return array
     */
    protected function genResult($pass, $type, $message = '', $data = [])
    {
        $result = [];
        $result['success'] = !!$pass;
        $result['type'] = $type;
        if (is_array($message)) {
            $data = $message;
            $message = '';
        }
        $message = $message ?: $this->getNotifyMessage($type);
        if (is_array($data) && count($data)) {
            try {
                $message = vsprintf($message, $data);
            } catch(\Exception $e) {
            }
        }
        $result['message'] = $message;
        return $result;
    }

    /**
     * get notify message
     * @param $name
     *
     * @return null
     */
    public function getNotifyMessage($name)
    {
        $messages = config('laravel-sms.notifies', []);
        if (array_key_exists($name, $messages)) {
            return $messages["$name"];
        }
        return $name;
    }
}
