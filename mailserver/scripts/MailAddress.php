<?php
/**
 * MailAddress.php —— 邮件地址解析与校验工具
 *
 * 纯静态工具类，无数据库依赖，被 Web 与 CLI 双侧共享。
 * 负责地址合法性校验、本域判定、显示名解析与地址列表拆分。
 */
class MailAddress
{
    /**
     * 校验邮件地址是否合法
     *
     * @param string $address 待校验地址（不含显示名）
     * @return boolean
     */
    public static function isValid($address)
    {
        if (empty($address) || !is_string($address)) {
            return false;
        }
        if (strlen($address) > 254) {
            return false;
        }
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * 取得地址的域名部分（小写）
     *
     * @param string $address
     * @return string|false 解析失败返回 false
     */
    public static function domainOf($address)
    {
        $pos = strrpos($address, "@");
        if ($pos === false) {
            return false;
        }
        return strtolower(substr($address, $pos + 1));
    }

    /**
     * 取得地址的用户名部分（@ 之前，小写）
     *
     * @param string $address
     * @return string|false
     */
    public static function userOf($address)
    {
        $pos = strrpos($address, "@");
        if ($pos === false) {
            return false;
        }
        return strtolower(substr($address, 0, $pos));
    }

    /**
     * 地址归一化：去除首尾空白与尖括号，统一转小写
     *
     * @param string $address
     * @return string
     */
    public static function normalize($address)
    {
        $address = trim((string) $address);
        $address = trim($address, "<>");
        return strtolower(trim($address));
    }

    /**
     * 本服务负责投递的域名列表（读取配置 MAIL_DOMAIN，逗号分隔）
     *
     * @return string[] 全部小写
     */
    public static function localDomains()
    {
        $raw = DotNetRegistry::Read("MAIL_DOMAIN", "localhost");
        $list = [];

        foreach (explode(",", (string) $raw) as $d) {
            $d = strtolower(trim($d));
            if ($d !== "") {
                $list[] = $d;
            }
        }

        return empty($list) ? ["localhost"] : $list;
    }

    /**
     * 判断地址是否属于本服务负责的域（决定「本域直投」还是「外域投递」）
     *
     * @param string $address
     * @return boolean
     */
    public static function isLocal($address)
    {
        $domain = self::domainOf(self::normalize($address));
        if ($domain === false) {
            return false;
        }
        return in_array($domain, self::localDomains(), true);
    }

    /**
     * 解析形如 `"张三" <a@b.com>` 或 `a@b.com` 的单个地址
     *
     * @param string $raw
     * @return array{name:string, address:string}|false 无法解析返回 false
     */
    public static function parseOne($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === "") {
            return false;
        }

        $name = "";
        $address = $raw;

        # 形如 显示名 <地址> 的写法：取尖括号内为地址，之前的部分为显示名
        if (preg_match('/^(.*)<([^<>]+)>\s*$/u', $raw, $m)) {
            $name = trim($m[1]);
            # 去掉显示名两侧可能存在的引号
            $name = trim($name, " \t\"'");
            $address = trim($m[2]);
        }

        $address = self::normalize($address);

        if (!self::isValid($address)) {
            return false;
        }

        return ["name" => $name, "address" => $address];
    }

    /**
     * 拆分逗号/分号分隔的地址列表
     *
     * 能正确处理显示名中含逗号的情况（如 `"李四, 经理" <b@c.com>, d@e.com`），
     * 依据是否处于引号内与尖括号内来决定分隔符是否生效。
     *
     * @param string|string[] $raw
     * @return array 每项为 ["name" => ..., "address" => ...]，非法项被丢弃
     */
    public static function parseList($raw)
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = self::splitAddressList((string) $raw);
        }

        $result = [];
        $seen = [];

        foreach ($parts as $p) {
            $one = self::parseOne($p);
            if ($one === false) {
                continue;
            }
            # 同一地址去重，保留首次出现的显示名
            if (isset($seen[$one["address"]])) {
                continue;
            }
            $seen[$one["address"]] = true;
            $result[] = $one;
        }

        return $result;
    }

    /**
     * 按引号与尖括号状态安全拆分地址列表字符串
     *
     * @param string $text
     * @return string[]
     */
    private static function splitAddressList($text)
    {
        $parts = [];
        $buffer = "";
        $inQuote = false;
        $inAngle = false;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($ch === '"') {
                # 引号内的分隔符不生效
                $inQuote = !$inQuote;
                $buffer .= $ch;
            } else if ($ch === '<' && !$inQuote) {
                $inAngle = true;
                $buffer .= $ch;
            } else if ($ch === '>' && !$inQuote) {
                $inAngle = false;
                $buffer .= $ch;
            } else if (($ch === ',' || $ch === ';') && !$inQuote && !$inAngle) {
                # 只有在引号与尖括号之外，逗号分号才作为分隔符
                $parts[] = $buffer;
                $buffer = "";
            } else {
                $buffer .= $ch;
            }
        }

        if (trim($buffer) !== "") {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /**
     * 将 ["name"=>, "address"=>] 结构格式化回可写入信头的字符串
     * 显示名含非 ASCII 字符时交由 MimeBuilder 做编码字编码
     *
     * @param array $one
     * @return string
     */
    public static function format($one)
    {
        $address = $one["address"];
        $name = isset($one["name"]) ? trim($one["name"]) : "";

        if ($name === "") {
            return $address;
        }

        return $name . " <" . $address . ">";
    }
}
