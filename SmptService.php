<?php
/**
 * Author:  Speauty
 * Email:   speauty@163.com
 * File:    Smtp.php
 * Created: 2019/10/25 上午10:17
 */

/**
 * Class Smtp
 * 参考https://segmentfault.com/a/1190000014789528
 * 在这个基础上,我主要强调了命名的规范性, 和增加一些注释, 方便个人理解使用
 */
class SmtpService
{
    /** 在 HELO 命令中使用*/
    private $hostName = 'localhost';
    /** SMTP服务地址  */
    private $smtpServer = 'smtp.163.com';
    /** SMTP服务端口  */
    private $smtpServerPort = 25;
    /** 设置连接socket的时限 */
    private $socketTimeout = 30;
    /** 日志记录路径 */
    private $logFilePath = '';
    /** 是否开启调试模式 */
    private $isDebug = false;
    /** 是否需要授权 */
    private $isAuth = false;
    /** SMTP服务账户 */
    private $smtpAccount = '';
    /** SMTP服务密码 */
    private $smtpPass = '';
    /** SOCK */
    private $sock = null;

    /**
     * 检测属性有效性
     * @throws Exception
     */
    private function checkPropertyValid()
    {
        if (
            empty($this->hostName) ||
            empty($this->smtpServer) ||
            empty($this->smtpServerPort) ||
            (
                $this->isAuth === true &&
                (
                    empty($this->smtpAccount) || empty($this->smtpPass)
                )
            )
        ) {
            throw new \Exception('the parameters received invalid.');
        }
    }

    /**
     * 设置属性值
     * @param string $key
     * @param $val
     * @param int $isThrow 是否抛出异常
     * @throws Exception
     */
    public function setAttr(string $key, $val, int $isThrow = 0):void
    {
        if (isset($this->$key)) {
            $this->$key = $val;
        } else {
            if ($isThrow) throw new \Exception("the property named {$key} not found.");
        }
    }

    /**
     * 架构函数
     * 接收参数并解析和判断有效性
     * Smtp constructor.
     * @param array $args
     */
    public function __construct(array $args)
    {
        if ($args) foreach ($args as $k => $v) $this->setAttr($k, $v);
        $this->checkPropertyValid();
    }

    /**
     * @param string $toMailAddress
     * @param string $fromMailAddress
     * @param array $others
     * @return bool
     */
    public function sendMail(string $toMailAddress, string $fromMailAddress, array $others):bool
    {
        $extArgs = [
            'subject' => '',
            'body' => '',
            'mailType' => '',
            'cc' => '',
            'bcc' => '',
            'additionalHeaders' => ''
        ];
        $extArgs = array_merge($extArgs, $others);
        $mailFrom = $this->getAddress($this->removeSpecialCharsFromStr($fromMailAddress));
        $body = preg_replace("/(^|(\r\n))(\.)/", "\1.\3", $extArgs['body']);
        $header = "MIME-Version:1.0\r\n";
        if($extArgs['mailType'] === "HTML"){
            $header .= "Content-Type:text/html\r\n";
        }
        $header .= "To: ".$toMailAddress."\r\n";
        if ($extArgs['cc'] != "") {
            $header .= "Cc: ".$extArgs['cc']."\r\n";
        }
        $header .= "From: $fromMailAddress<".$fromMailAddress.">\r\n";

        $header .= "Subject: ".$extArgs['subject']."\r\n";
        $header .= $extArgs['additionalHeaders'];
        $header .= "Date: ".date("r")."\r\n";
        $header .= "X-Mailer:By Redhat (PHP/".phpversion().")\r\n";
        list($msec, $sec) = explode(" ", microtime());
        $header .= "Message-ID: <".date("YmdHis", $sec).".".($msec*1000000).".".$mailFrom.">\r\n";

        $toMailArr = explode(",", $this->removeSpecialCharsFromStr($toMailAddress));

        if ($extArgs['cc'] != "") {
            $toMailArr = array_merge($toMailArr, explode(",", $this->removeSpecialCharsFromStr($extArgs['cc'])));
        }
        if ($extArgs['bcc'] != "") {
            $toMailArr = array_merge($toMailArr, explode(",", $this->removeSpecialCharsFromStr($extArgs['bcc'])));
        }
        $sent = true;

        foreach ($toMailArr as $rcptTo) {
            $rcptTo = $this->getAddress($rcptTo);
            if (!$this->openSocket($rcptTo)) {
                $this->log("Error: Cannot send email to ".$rcptTo."\n");
                $sent = false;
                continue;
            }
            if ($this->runSock($this->hostName, $mailFrom, $rcptTo, $header, $body)) {
                $this->log("E-mail has been sent to <".$rcptTo.">\n");
            } else {
                $this->log("Error: Cannot send email to <".$rcptTo.">\n");
                $sent = false;

            }
            fclose($this->sock);
            $this->log("Disconnected from remote host\n");
        }
        return $sent;
    }

    /**
     * 执行
     * @param string $hostName
     * @param string $from
     * @param string $to
     * @param string $header
     * @param string $body
     * @return bool
     */
    private function runSock(string $hostName, string $from, string $to, string $header, string $body = "")
    {

        if (!$this->sockPutCmd("HELO", $hostName)) {
            return $this->sockError("sending HELO command");
        }

        if($this->isAuth){
            if (!$this->sockPutCmd("AUTH LOGIN", base64_encode($this->smtpAccount))) {
                return $this->sockError("sending HELO command");
            }
            if (!$this->sockPutCmd("", base64_encode($this->smtpPass))) {
                return $this->sockError("sending HELO command");
            }
        }

        if (!$this->sockPutCmd("MAIL", "FROM:<".$from.">")) {
            return $this->sockError("sending MAIL FROM command");
        }

        if (!$this->sockPutCmd("RCPT", "TO:<".$to.">")) {
            return $this->sockError("sending RCPT TO command");
        }

        if (!$this->sockPutCmd("DATA")) {
            return $this->sockError("sending DATA command");
        }

        if (!$this->buildSockContent($header, $body)) {
            return $this->sockError("sending message");
        }

        if (!$this->addSockEom()) {
            return $this->sockError("sending <CR><LF>.<CR><LF> [EOM]");
        }

        if (!$this->sockPutCmd("QUIT")) {
            return $this->sockError("sending QUIT command");
        }

        return true;
    }

    /**
     * 连接socket
     * @param string $address
     * @return bool
     */
    private function openSocket(string $address):bool
    {
        if ($this->smtpServer == "") {
            return $this->getSmtpMxRR($address);
        } else {
            return $this->retrySock();
        }
    }

    /**
     * 重试Sock连接
     * @return bool
     */
    private function retrySock():bool
    {
        $this->log("Trying to ".$this->smtpServer.":".$this->smtpServerPort."\n");
        $this->sock = @fsockopen($this->smtpServer, $this->smtpServerPort, $errNo, $errStr, $this->socketTimeout);
        if (!($this->sock && $this->testSmtpServerResponse())) {
            $this->log("Error: Cannot connenct to relay host ".$this->smtpServer."\n");
            $this->log("Error: ".$errStr." (".$errNo.")\n");
            return false;
        }
        $this->log("Connected to relay host ".$this->smtpServer."\n");
        return true;
    }

    /**
     * 获取并检测邮件交换记录
     * @param string $address
     * @return bool
     */
    private function getSmtpMxRR(string $address):bool
    {
        $domain = preg_replace("/^.+@([^@]+)$/", "\1", $address);
        /** getmxrr 获取互联网主机名对应的 MX 记录 */
        /** 邮件交换记录 (MX record)是域名系统（DNS）中的一种资源记录类型，用于指定负责处理发往收件人域名的邮件服务器 */
        if (!@getmxrr($domain, $mxHosts)) {
            $this->log("Error: Cannot resolve MX \"".$domain."\"\n");
            return false;
        }
        foreach ($mxHosts as $host) {
            $this->log("Trying to ".$host.":".$this->smtpServerPort."\n");
            $this->sock = @fsockopen($this->smtpServer, $this->smtpServerPort, $errNo, $errStr, $this->socketTimeout);
            if (!($this->sock && $this->testSmtpServerResponse())) {
                $this->log("Warning: Cannot connect to mx host ".$host."\n");
                $this->log("Error: ".$errStr." (".$errNo.")\n");
                continue;
            }
            $this->log("Connected to mx host ".$host."\n");
            return true;
        }
        $this->log("Error: Cannot connect to any mx hosts (".implode(", ", $mxHosts).")\n");
        return false;
    }

    /**
     * 将头部和内容体输入到sock中去
     * @param string $header
     * @param string $body
     * @return bool
     */
    private function buildSockContent(string $header, string $body):bool
    {
        fputs($this->sock, $header."\r\n".$body);
        $this->debug("> ".str_replace("\r\n", "\n"."> ", $header."\n> ".$body."\n> "));
        return true;
    }

    /**
     * 添加会话结束符
     * @return bool
     */
    private function addSockEom():bool
    {
        fputs($this->sock, "\r\n.\r\n");
        $this->debug(". [EOM]\n");
        return $this->testSmtpServerResponse();
    }

    /**
     * 检测SMTP服务器是否正常响应
     * @return bool
     */
    private function testSmtpServerResponse():bool
    {
        $response = str_replace("\r\n", "", fgets($this->sock, 512));
        $this->debug($response."\n");
        if (!preg_match("/^[23]/", $response)) {
            fputs($this->sock, "QUIT\r\n");
            fgets($this->sock, 512);
            $this->log("Error: Remote host returned \"".$response."\"\n");
            return false;
        }
        return true;
    }

    /**
     * 补充命令
     * @param string $cmd
     * @param string $arg
     * @return bool
     */
    private function sockPutCmd(string $cmd, string $arg = ""):bool
    {
        if ($arg != "") $cmd = $cmd==""?$arg:($cmd." ".$arg);
        fputs($this->sock, $cmd."\r\n");
        $this->debug("> ".$cmd."\n");
        return $this->testSmtpServerResponse();
    }

    /**
     * sock错误
     * @param string $str
     * @return bool
     */
    private function sockError(string $str):bool
    {
        $this->log("Error: Error occurred while ".$str.".\n");
        return false;
    }

    /**
     * 进行日志记录, 如果日志文件路径存在的话
     * @param string $msg
     * @return bool
     */
    private function log(string $msg):bool
    {
        $this->debug($msg);
        if ($this->logFilePath == "") return true;
        $msg = date("M d H:i:s ").get_current_user()."[".getmypid()."]: ".$msg;
        if (!@file_exists($this->logFilePath) || !($fp = @fopen($this->logFilePath, "a"))) {
            $this->debug("Warning: Cannot open log file \"".$this->logFilePath."\"\n");
            return false;
        }
        flock($fp, LOCK_EX);
        fputs($fp, $msg);
        fclose($fp);
        return true;
    }

    /**
     * 移除字符串中的特殊字符
     * @param string $str
     * @return string
     */
    private function removeSpecialCharsFromStr(string $str):string
    {
        $specialChars = "/\([^()]*\)/";
        while (preg_match($specialChars, $str)) {
            $str = preg_replace($specialChars, "", $str);
        }
        return $str;
    }

    /**
     * 获取邮件地址
     * @param string $address
     * @return string
     */
    private function getAddress(string $address):string
    {
        /** 正则替换制表符 */
        $address = preg_replace("/([ \t\r\n])+/", "", $address);
        /** 正则替换 */
        $address = preg_replace("/^.*<(.+)>.*$/", "\1", $address);
        return $address;

    }

    /**
     * 调试输出信息
     * @param string $msg
     */
    private function debug(string $msg)
    {
        if ($this->isDebug) echo $msg;
    }
}

/** 实例 */
$startTime = microtime(true);
$args = [
    'smtpServer' => 'smtp.163.com',
    'smtpServerPort' => 25,
    'isAuth' => true,
    'smtpAccount' => 'your account',
    'smtpPass' => 'your authorization code'
];

$smtpusermail = "";//SMTP服务器的用户邮箱
$smtpemailto = "";//发送给谁
$extArgs = [
    'subject' => 'test',
    'body' => '<h1>Hello world</h1>',
    'mailType' => 'HTML'
];
/** SMTP服务配置信息 */
$smtp = new SmtpService($args);//这里面的一个true是表示使用身份验证,否则不使用身份验证.
/** 发送邮件 */
$state = $smtp->sendMail($smtpemailto,$smtpusermail,$extArgs);

echo "<div style='width:300px; margin:36px auto;'>";
if($state==""){
    echo "对不起，邮件发送失败！请检查邮箱填写是否有误。";
    echo "<a href='index.html'>点此返回</a>";
    exit();
}
echo "恭喜！邮件发送成功！！";
echo "<a href='index.html'>点此返回</a>";
echo "</div>";
echo PHP_EOL.'time used:'.(microtime(true)-$startTime);
