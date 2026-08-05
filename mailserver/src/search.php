<?php
/**
 * src/search.php —— 全文搜索接口控制器
 *
 * 挂载于 api.php 入口：/api.php?ctl=search&app=方法名
 * 重写后：/api/search/方法名
 */

class SearchApp
{
    /**
     * 全文检索邮件
     *
     * 支持组合查询：
     *   - keyword：主题 / 发件人 / 收件人 / 正文关键词
     *   - folder_id / folder_type：限定文件夹
     *   - start / end：时间范围
     *   - has_attach：附件过滤（0=不限、1=有、-1=无）
     *   - direction：方向过滤（in/out）
     *
     * @uses api
     * @access user|admin
     * @method GET|POST
     * @origin *
     */
    public function query()
    {
        $user = require_login();

        $paging = input_paging(20, 100);

        $keyword    = input("keyword", "");
        $folderId   = input_int("folder_id", 0);
        $folderType = input("folder_type", "");
        $startTime  = input("start", "");
        $endTime    = input("end", "");
        $hasAttach  = input_int("has_attach", 0);
        $direction  = input("direction", "");
        $page       = max(1, (int) input("page", 1));
        $pageSize   = min((int) input("limit", 20), 100);

        # 按类型定位文件夹
        if ($folderId <= 0 && $folderType !== "") {
            $folderId = FolderService::idOfType($user["id"], $folderType);
        }

        # 校验文件夹归属
        if ($folderId > 0) {
            if (FolderService::findOwned($folderId, $user["id"]) === null) {
                controller::error("folder not found", 404);
            }
        }

        # 校验时间格式
        $startTime = $this->normalizeTime($startTime);
        $endTime   = $this->normalizeTime($endTime);

        if ($startTime !== null && $endTime !== null && $startTime > $endTime) {
            controller::error("start time is after end time", 400);
        }

        # 执行检索
        $result = SearchService::query(
            $user["id"],
            $keyword,
            $folderId,
            $startTime,
            $endTime,
            $hasAttach,
            $direction,
            $page,
            $pageSize
        );

        controller::success($result);
    }

    /**
     * 快速搜索（主题前缀匹配，用于自动补全）
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     * @require keyword=string
     */
    public function suggest()
    {
        $user = require_login();

        $keyword = input("keyword", "");
        $limit   = min((int) input("limit", 10), 30);

        if (trim($keyword) === "") {
            controller::success(["suggestions" => []]);
        }

        $results = SearchService::quickBySubject($user["id"], $keyword, $limit);

        controller::success(["suggestions" => $results]);
    }

    // =================================================================
    // 辅助
    // =================================================================

    /**
     * 规范化时间字符串为 "YYYY-MM-DD HH:MM:SS" 格式
     *
     * 支持 ISO 8601、日期格式、时间戳（秒）等多种输入。
     *
     * @param string $time
     * @return string|null
     */
    private function normalizeTime($time)
    {
        if ($time === "" || $time === null) {
            return null;
        }

        # 纯数字：按 Unix 时间戳（秒）处理
        if (is_numeric($time) && (int) $time > 0) {
            return date("Y-m-d H:i:s", (int) $time);
        }

        $ts = strtotime($time);

        if ($ts === false) {
            return null;
        }

        return date("Y-m-d H:i:s", $ts);
    }
}
