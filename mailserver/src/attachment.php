<?php
/**
 * src/attachment.php —— 附件接口控制器
 *
 * 挂载于 api.php 入口：/api.php?ctl=attachment&app=方法名
 * 重写后：/api/attachment/方法名
 *
 * 分片上传的完整调用流程：
 *   1) POST /api/attachment/init      提交文件名、总大小、校验值 → 得到 upload_id
 *      若返回 instant=true 表示秒传命中，直接拿到 attachment_id，流程结束
 *   2) POST /api/attachment/chunk     逐片上传（multipart 表单，字段名 chunk）
 *   3) GET  /api/attachment/progress  查询已完成分片，用于断点续传
 *   4) POST /api/attachment/complete  合并落盘 → 得到 attachment_id
 */

class AttachmentApp
{
    /**
     * 初始化上传会话
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require filename=string|total_size=i32
     */
    public function init()
    {
        $user = require_login();

        $result = AttachmentService::initUpload(
            $user["id"],
            input("filename", ""),
            input_int("total_size", 0),
            input("checksum", ""),
            input_int("chunk_size", 0)
        );

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success($result["data"]);
    }

    /**
     * 上传一个分片
     *
     * 请求为 multipart/form-data，字段：
     *   upload_id   上传会话标识
     *   index       分片序号（从 0 开始）
     *   chunk       分片二进制数据（文件字段）
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     */
    public function chunk()
    {
        $user = require_login();

        # 分片上传走 multipart 表单，数据在 $_POST / $_FILES 中，
        # 不经过 JSON 请求体，因此直接用 WebRequest 读取
        $uploadId = WebRequest::get("upload_id", "");
        $index = WebRequest::getInteger("index", -1, false);

        if (empty($uploadId)) {
            controller::error("upload_id is required", 400);
        }

        if ($index < 0) {
            controller::error("index is required and must be zero or greater", 400);
        }

        if (!isset($_FILES["chunk"])) {
            controller::error("chunk file field is required", 400);
        }

        $file = $_FILES["chunk"];

        if ($file["error"] !== UPLOAD_ERR_OK) {
            controller::error(self::uploadErrorText($file["error"]), 400);
        }

        $result = AttachmentService::saveChunk(
            $uploadId,
            $user["id"],
            $index,
            $file["tmp_name"],
            true
        );

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success($result["data"]);
    }

    /**
     * 查询上传进度
     *
     * @uses api
     * @access user|admin
     * @method GET|POST
     * @origin *
     * @require upload_id=string
     */
    public function progress()
    {
        $user = require_login();

        $result = AttachmentService::progress(input("upload_id", ""), $user["id"]);

        if (!$result["ok"]) {
            controller::error($result["error"], 404);
        }

        controller::success($result["data"]);
    }

    /**
     * 合并分片，完成上传
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require upload_id=string
     */
    public function complete()
    {
        $user = require_login();

        # 合并大文件可能耗时较久，取消执行时间限制
        set_time_limit(0);

        $result = AttachmentService::complete(input("upload_id", ""), $user["id"]);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success($result["data"]);
    }

    /**
     * 下载附件
     *
     * 支持 Range 请求头，可断点续传；大文件流式输出，内存占用恒定。
     *
     * 注意：本方法直接输出二进制流而非 JSON，
     * 因此使用 @uses router 让框架不要自动发送 JSON 的 Content-Type。
     *
     * @uses router
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function download()
    {
        $user = require_login();

        $id = WebRequest::getInteger("id", 0);
        $inline = WebRequest::getBool("inline");

        $att = AttachmentService::findAccessible($id, $user["id"]);

        if ($att === null) {
            controller::error("attachment not found", 404);
        }

        # 内部直接输出并 exit
        AttachmentService::streamDownload($att, $inline);
    }

    /**
     * 查询附件信息
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function info()
    {
        $user = require_login();

        $att = AttachmentService::findAccessible(WebRequest::getInteger("id", 0), $user["id"]);

        if ($att === null) {
            controller::error("attachment not found", 404);
        }

        controller::success(AttachmentService::publicView($att));
    }

    /**
     * 删除附件
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function remove()
    {
        $user = require_login();

        $result = AttachmentService::remove(input_int("id", 0), $user["id"]);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success(["message" => "attachment removed"]);
    }

    /**
     * 把 PHP 上传错误码翻译为可读描述
     *
     * @param integer $code
     * @return string
     */
    private static function uploadErrorText($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return "chunk exceeds upload_max_filesize in php.ini";
            case UPLOAD_ERR_FORM_SIZE:
                return "chunk exceeds MAX_FILE_SIZE in the form";
            case UPLOAD_ERR_PARTIAL:
                return "chunk was only partially uploaded";
            case UPLOAD_ERR_NO_FILE:
                return "no chunk data was uploaded";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "missing temporary folder on server";
            case UPLOAD_ERR_CANT_WRITE:
                return "failed to write chunk to disk";
            case UPLOAD_ERR_EXTENSION:
                return "upload stopped by a php extension";
            default:
                return "unknown upload error";
        }
    }
}
