<?php
/**
 * SearchService.php —— 全文搜索服务
 *
 * 按主题、发件人、收件人、正文关键词组合检索，
 * 支持限定文件夹、时间范围、是否含附件等过滤条件。
 * 结果分页返回并附带命中总数。
 *
 * 所有 SQL 通过 Table 模型构建，严禁字符串拼接。
 */

namespace scripts;

use \Table;
use \WebRequest;
use \ValueEnumerable;

class SearchService
{
    /** @var integer 默认每页条数 */
    const DEFAULT_PAGE_SIZE = 20;

    /** @var integer 最大每页条数（防滥用） */
    const MAX_PAGE_SIZE = 100;

    // =================================================================
    // 全文检索
    // =================================================================

    /**
     * 多条件组合搜索邮件，返回分页结果
     *
     * 参数全部由调用方负责取值与校验；
     * 本方法只负责构建查询条件与返回分页数据。
     *
     * @param integer $userId     当前用户 id
     * @param string  $keyword    检索关键词
     * @param integer $folderId   指定文件夹（0 表示全部）
     * @param string  $startTime  起始时间（"YYYY-MM-DD HH:MM:SS"），null 表示不限
     * @param string  $endTime    截止时间，null 表示不限
     * @param integer $hasAttach  附件过滤（0=不限, 1=有附件, -1=无附件）
     * @param string  $direction  方向过滤（"in"/"out"/""）
     * @param integer $page       页码（从 1 开始）
     * @param integer $pageSize   每页条数
     * @return array{list:array, total:int, page:int, total_page:int}
     */
    public static function query(
        $userId,
        $keyword = "",
        $folderId = 0,
        $startTime = null,
        $endTime = null,
        $hasAttach = 0,
        $direction = "",
        $page = 1,
        $pageSize = 20
    ) {
        $userId   = (int) $userId;
        $folderId = (int) $folderId;
        $page     = max(1, (int) $page);
        $pageSize = min(max(1, (int) $pageSize), self::MAX_PAGE_SIZE);

        $baseTable = MailService::table();
        $baseTable = $baseTable->where(["user_id" => $userId, "is_draft" => 0]);

        # ---- 文件夹过滤 ----
        if ($folderId > 0) {
            $baseTable = $baseTable->where(["folder_id" => $folderId]);
        }

        # ---- 方向过滤 ----
        if (!empty($direction) && in_array($direction, ["in", "out"], true)) {
            $baseTable = $baseTable->where(["direction" => $direction]);
        }

        # ---- 附件过滤 ----
        if ($hasAttach === 1) {
            $baseTable = $baseTable->where(["has_attach" => 1]);
        } else if ($hasAttach === -1) {
            $baseTable = $baseTable->where(["has_attach" => 0]);
        }

        # ---- 时间范围 ----
        if (!empty($startTime)) {
            $baseTable = $baseTable->where(new ValueEnumerable([
                "mail_time" => [">=" => $startTime]
            ]));
        }

        if (!empty($endTime)) {
            $baseTable = $baseTable->where(new ValueEnumerable([
                "mail_time" => ["<=" => $endTime]
            ]));
        }

        # ---- 关键词搜索 ----
        if (!empty(trim($keyword))) {
            $kw = trim($keyword);

            # 多字段 OR 条件：主题、发件人名/地址、收件人摘要、正文
            $baseTable = $baseTable->where(new ValueEnumerable([
                "subject|from_name|from_address|to_summary|body_text|body_html" => "like('%{$kw}%')"
            ]));
        }

        # 计数
        $total = (int) $baseTable->count();

        # 分页查询，只取摘要所需列，不取大字段
        $columns = "id,folder_id,thread_id,message_id,from_address,from_name,"
                 . "to_summary,subject,summary,size,has_attach,"
                 . "is_read,is_starred,is_draft,direction,send_status,mail_time";

        $offset = ($page - 1) * $pageSize;

        $rows = $baseTable
            ->select($columns)
            ->order_by("mail_time", true)
            ->limit($pageSize)
            ->offset($offset);

        if ($rows === false) {
            $rows = [];
        }

        # 若 select 未正确截断，手动截取
        if (count($rows) > $pageSize) {
            $rows = array_slice($rows, 0, $pageSize);
        }

        # 转换为摘要视图
        $list = [];
        foreach ($rows as $row) {
            $list[] = MailService::summaryView($row);
        }

        $totalPage = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        return [
            "list"       => $list,
            "total"      => $total,
            "page"       => $page,
            "total_page" => $totalPage
        ];
    }

    // =================================================================
    // 快速搜索（仅标题，用于自动补全）
    // =================================================================

    /**
     * 按主题前缀快速搜索（建议供自动补全使用）
     *
     * @param integer $userId
     * @param string  $prefix
     * @param integer $limit
     * @return array
     */
    public static function quickBySubject($userId, $prefix, $limit = 10)
    {
        $userId = (int) $userId;
        $prefix = trim($prefix);

        if ($prefix === "") {
            return [];
        }

        $rows = MailService::table()
            ->where(["user_id" => $userId, "is_draft" => 0])
            ->where(new ValueEnumerable([
                "subject" => "like('{$prefix}%')"
            ]))
            ->select("id,subject")
            ->order_by("mail_time", true)
            ->limit($limit);

        if ($rows === false) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                "id"      => (int) $row["id"],
                "subject" => $row["subject"]
            ];
        }

        return $result;
    }

    // =================================================================
    // 邮件列表（通用分页，供文件夹与线程控制器复用）
    // =================================================================

    /**
     * 按文件夹分页列出邮件摘要
     *
     * 仅选取摘要所需列，不返回正文大字段。
     * 用于文件夹邮件列表与线程详情内的邮件列表。
     *
     * @param integer $folderId
     * @param integer $userId
     * @param integer $page
     * @param integer $pageSize
     * @return array
     */
    public static function listByFolder($folderId, $userId, $page = 1, $pageSize = 20)
    {
        $folderId = (int) $folderId;
        $userId   = (int) $userId;
        $page     = max(1, (int) $page);
        $pageSize = min(max(1, (int) $pageSize), self::MAX_PAGE_SIZE);

        $table = MailService::table()
            ->where(["folder_id" => $folderId, "user_id" => $userId, "is_draft" => 0]);

        $total = (int) $table->count();

        $columns = MailService::summaryFields();
        $offset  = ($page - 1) * $pageSize;

        $rows = $table
            ->select($columns)
            ->order_by("mail_time", true)
            ->limit($pageSize)
            ->offset($offset);

        if ($rows === false) {
            $rows = [];
        }

        if (count($rows) > $pageSize) {
            $rows = array_slice($rows, 0, $pageSize);
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = MailService::summaryView($row);
        }

        $totalPage = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        return [
            "list"       => $list,
            "total"      => $total,
            "page"       => $page,
            "total_page" => $totalPage
        ];
    }

    /**
     * 按线程分页列出邮件（时间正序，用于会话详情）
     *
     * @param integer $threadId
     * @param integer $userId
     * @param integer $page
     * @param integer $pageSize
     * @return array
     */
    public static function listByThread($threadId, $userId, $page = 1, $pageSize = 50)
    {
        $threadId = (int) $threadId;
        $userId   = (int) $userId;
        $page     = max(1, (int) $page);
        $pageSize = min(max(1, (int) $pageSize), self::MAX_PAGE_SIZE);

        $table = MailService::table()
            ->where(["thread_id" => $threadId, "user_id" => $userId, "is_draft" => 0]);

        $total = (int) $table->count();

        if ($total === 0) {
            return self::emptyPage();
        }

        $columns = MailService::summaryFields();
        $offset  = ($page - 1) * $pageSize;

        $rows = $table
            ->select($columns)
            ->order_by("mail_time", false)
            ->limit($pageSize)
            ->offset($offset);

        if ($rows === false) {
            $rows = [];
        }

        if (count($rows) > $pageSize) {
            $rows = array_slice($rows, 0, $pageSize);
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = MailService::summaryView($row);
        }

        $totalPage = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        return [
            "list"       => $list,
            "total"      => $total,
            "page"       => $page,
            "total_page" => $totalPage
        ];
    }

    // =================================================================
    // 辅助
    // =================================================================

    /**
     * 空分页结果
     *
     * @return array
     */
    private static function emptyPage()
    {
        return [
            "list"       => [],
            "total"      => 0,
            "page"       => 1,
            "total_page" => 0
        ];
    }
}
