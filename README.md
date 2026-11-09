# MuseApi —— Typecho 评论 / 文章 / 图片管理 API（Token 鉴权）

给 Muse（或其他可信客户端）用的最小化管理接口，让 Typecho 博客
能像 WordPress 那样走 API 托管评论、文章和图片上传。

- **只做三件事**：管理评论、管理文章、上传图片。编辑级权限。
- **不做**：用户管理、设置修改、链接、主题/插件、备份、除图片上传外
  的文件操作。
- **v1.1 没有删除文章接口**：删文章请在后台手动操作（见下方说明）。
- **鉴权**：静态 Token，放在 `X-Muse-Token` 请求头里（不在 URL 里传）。
- **要求**：Typecho 1.3.0，PHP 8.1+（代码用了 PHP 8.1 的 `never` 返回类型）。

## 安装

1. 把 `MuseApi` 文件夹（内含 `Plugin.php`、`Action.php`）上传到
   `usr/plugins/`，即 `usr/plugins/MuseApi/Plugin.php`、
   `usr/plugins/MuseApi/Action.php`。
   **注意权限**：两个 `.php` 文件必须能被 Web 服务器（PHP 进程）
   读取，一般是 `644` 或 `664`。宝塔面板点“解压”时，它的 umask
   （027）会把文件压成 `640`，PHP 读不到，后台就会显示“此插件文件
   已经损坏或者被不安全移除”——这时把两个文件的权限改成 `644` 或
   `664` 再刷新页面即可，**不要点禁用**（停用会删 Token）。
   用宝塔终端解压可以先 `umask 022` 再解压，就不用事后改权限。
   你的步骤没错，这是宝塔解压的默认行为，不是装错了。
2. 后台 → 插件 → 找到 **MuseApi** → 启用。
   启用时自动生成一个 48 字符的随机 Token 并注册接口路由。
3. 后台 → 插件 → MuseApi 的「设置」，复制 **API Token**，
   存入凭证库（只存凭证库，不要发到聊天记录、不要放进 URL）。
4. 设置里还有 **API 用户名**（默认 `muse`），接口以该用户的身份执行
   操作，该用户必须是编辑或管理员。

接口地址：`POST https://your-blog.example/index.php/action/muse-api`

> **从 v1.1 升级到 v1.1.1**：直接用新版 `Plugin.php`、`Action.php`
> 覆盖 `usr/plugins/MuseApi/` 下的同名文件即可，**不需要停用再启用**，
> Token 存在数据库配置里、不会变，接口路由也没有变。
> （停用会删配置、重新启用会换 Token，只有换 Token 时才需要停用。）

> **版本历史**：v1.1.0 增加了 `upload_image`；v1.1.1 修复了上传
> 的 MIME 检测——有的服务器 fileinfo 的 magic 库损坏，会把所有文件
> 都认成 `application/octet-stream`，导致真图片也被拒绝，v1.1.1
> 在这种情况下改用 `getimagesize()` + 文件头特征码二次核对。

## Token 管理

- **查看**：插件设置页的「API Token」一栏。
- **重新生成**：设置页勾选「重新生成 Token」→ 保存设置。
  新 Token 立即生效，旧 Token 当即作废，记得重新复制到凭证库。
- **停用再启用**：Typecho 停用插件时会删除插件配置（Token 一并删除），
  重新启用后会生成一个新 Token，须重新复制到凭证库。
- Token 是单一持有者密钥，拿到它就等于拿到编辑权限，见 `SECURITY.md`。

## 调用格式

```
POST /index.php/action/muse-api
Content-Type: application/json
X-Muse-Token: <Token>

{"action": "list_comments", "status": "spam", "page": 1, "pageSize": 20}
```

成功响应（HTTP 200）：

```json
{"success": true, "comments": [...], "total": 3, "page": 1, "pageSize": 20}
```

失败响应（HTTP 状态码见下）：

```json
{"success": false, "error": "Token 无效", "code": "unauthorized"}
```

> `upload_image` 是唯一的例外：它用 `multipart/form-data`（见
> 「图片上传接口」一节），其余 12 个接口都用 JSON。

| HTTP 状态 | 含义 |
| --- | --- |
| 400 | 请求体不是 JSON 对象、未知的 action、参数不合法；上传时文件缺失、类型不符、超过 10MB 也属此类 |
| 401 | 缺少 `X-Muse-Token` 请求头，或 Token 错误 |
| 403 | 无权操作（核心组件报的权限问题） |
| 404 | 文章/评论不存在 |
| 405 | 不是 POST 请求 |
| 500 | 插件未配置 Token、API 用户不存在/权限不足、服务器内部错误 |

## 评论接口

### list_comments —— 列评论

```json
{"action": "list_comments", "status": "all", "page": 1, "pageSize": 20}
{"action": "list_comments", "status": "spam", "cid": 123}
```

- `status`：`all`（默认）、`approved`、`waiting`、`spam`。
  `all` 是三类合并后按 coid 倒序分页。
- `cid`（可选）：只列某一篇文章的评论。
- `page`（默认 1）、`pageSize`（默认 20，最大 100）。
- 返回：`comments` 数组、`total`、`page`、`pageSize`。
- 每条评论含：`coid`、`cid`、`parent`、`authorId`、`status`、`type`、
  `author`、`mail`、`url`、`ip`、`text`、`created`（时间戳）、
  `title`（文章标题）、`permalink`（未通过的评论链接为 `#comment-<coid>`，
  这是 Typecho 核心行为）。

### get_comment —— 取一条评论

```json
{"action": "get_comment", "coid": 123}
```

不存在返回 404。

### approve_comment / spam_comment / wait_comment —— 标记状态

```json
{"action": "approve_comment", "coid": 123}
{"action": "spam_comment", "coid": 123}
{"action": "wait_comment", "coid": 123}
```

返回更新后的评论。核心会自动维护文章的评论数（通过 ↔ 其他状态时
增减 `commentsNum`）。

### delete_comment —— 删除评论

```json
{"action": "delete_comment", "coid": 123}
```

返回 `{"success": true, "deleted": 123}`。不存在返回 404。
删除后文章评论数相应减少（仅当原先是已通过状态）。

### reply_comment —— 回复评论

```json
{"action": "reply_comment", "coid": 123, "text": "感谢留言！"}
```

以 API 用户的身份发表回复，状态为已通过，`parent` 为原评论。
返回新回复的评论对象（含新 `coid`）。

### edit_comment —— 编辑评论

```json
{"action": "edit_comment", "coid": 123, "text": "新内容", "author": "张三", "mail": "a@b.c", "url": "https://example.com", "created": 1730000000}
```

只传要改的字段；没传的沿用原值。`text` 最长 10000 字符。

## 文章接口

### list_posts —— 列文章

```json
{"action": "list_posts", "status": "all", "page": 1, "pageSize": 20}
{"action": "list_posts", "status": "draft"}
{"action": "list_posts", "status": "publish", "category_id": 3, "keywords": "南京"}
```

- `status`：`all`（默认，含草稿）、`publish`、`draft`、`waiting`、
  `private`、`hidden`。
- `category_id`（可选）：按分类 mid 筛选；`keywords`（可选）：按标题搜索。
- 返回：`posts` 数组、`total`、`page`、`pageSize`。
- 每篇文章含：`cid`、`title`、`slug`、`text`、`isMarkdown`、`status`、
  `type`（`post` / `post_draft`）、`created`、`modified`（时间戳）、
  `authorId`、`author`（作者昵称）、`categories`（`[{mid, name, slug}]`）、
  `tags`（名字数组）、`allowComment`、`allowPing`、`allowFeed`、
  `hasPassword`、`hasDraft`、`commentsNum`、`permalink`。
- **注意**：`text` 返回时不带开头的 `<!--markdown-->` 标记，
  `isMarkdown` 表示它是不是 Markdown 格式。
- **页面（page）不在 v1 范围内**：只列 `post` / `post_draft`。

### get_post —— 取一篇文章

```json
{"action": "get_post", "cid": 123}
```

不存在返回 404。**如果存在草稿/修订，返回的是最新可编辑版本**
（`text` 取草稿内容，`hasDraft` 为 `true`，`type`/`status` 仍为
已发布文章的值）——这与后台编辑页看到的内容一致。

### create_post —— 新建文章

```json
{
  "action": "create_post",
  "title": "标题",
  "text": "正文（Markdown）",
  "status": "draft",
  "slug": "my-post",
  "categories": ["随笔"],
  "category_ids": [3],
  "tags": ["南京", "旅行"],
  "allowComment": true,
  "allowPing": true,
  "allowFeed": true,
  "password": "1234",
  "markdown": true,
  "created": 1730000000
}
```

- `status`：`draft`（默认）或 `publish`。只支持这两种。
- `title`（默认「未命名文档」）、`text`（默认空）、`slug`（默认空，
  由核心按标题生成）。
- 分类二选一：`categories`（名字数组）或 `category_ids`（mid 数组）。
  名字不存在会报 400，**不会自动创建分类**。都不传时用站点默认分类。
- `tags`：字符串（逗号分隔）或字符串数组，最多 50 个。
- `allowComment` / `allowPing` / `allowFeed`：不传时用站点默认配置。
- `password`：设置后文章为密码保护（发布时）；不传为空。
- `markdown`：默认 `true`。`text` 开头的 `<!--markdown-->` 标记
  会被去掉后由核心按此参数重加。
- `created`：时间戳；不传时用当前时间（核心对无 `created` 的草稿
  会写 0，不便展示，故默认填当前时间）。
- 返回新建的文章对象。

### update_post —— 更新文章

```json
{"action": "update_post", "cid": 123, "title": "新标题", "text": "新正文"}
{"action": "update_post", "cid": 123, "status": "publish"}
```

- 只传要改的字段；没传的沿用原值（标题、正文、别名、分类、标签、
  评论/引用/订阅开关、密码、发布时间都一样）。
- `status` 省略时保持原状态；可改为 `draft` / `publish` /
  `private` / `waiting` / `hidden`。
- **已发布文章传 `status: "draft" 不会下架**：核心会保存一份草稿修订，
  已发布内容保持不变，返回里多一个 `draftCid`（修订行的 cid）。
  想发布草稿时传 `status: "publish"`（草稿原地转为已发布，cid 不变）。
- `status` 为 `private` / `waiting` / `hidden` 时，核心会清空文章密码
  （Typecho 核心行为）；带密码的已发布文章请保持 `status: "publish"`
  并传 `password`。
- `markdown` 不传时沿用原文格式；`text` 开头的 `<!--markdown-->`
  标记会被去掉后重加。
- 不存在返回 404；分类名不存在返回 400。

## 图片上传接口

### upload_image —— 上传一张图片

与其他接口不同，这个接口用 `multipart/form-data`，不要发 JSON：

```bash
curl -X POST "https://your-blog.example/index.php/action/muse-api" \
  -H "X-Muse-Token: <Token>" \
  -F "action=upload_image" \
  -F "file=@/path/to/photo.jpg"
```

- 表单字段：`action` 固定为 `upload_image`，文件字段名固定为 `file`。
- 只接受图片：`jpg`、`jpeg`、`png`、`webp`、`gif`。
  按扩展名和实测 MIME 类型双重校验，不信任客户端自报的 MIME；
  文本文件改 `.jpg` 后缀也会被拒绝。
- 单文件上限 **10 MB**，超过返回 400（`file_too_large`）。
- 文件走 Typecho 核心自己的上传路径（`Widget\Upload::uploadHandle`），
  与后台上传完全一致：存入 `usr/uploads/YYYY/MM/`，文件名由核心生成
  （crc32 随机数 + 扩展名），同名自动换名不覆盖；同时在内容表里生成一
  条 `attachment` 记录（标题为原文件名，作者为 API 用户），与后台
  上传的附件无区别，媒体库可见。

成功响应（HTTP 200）：

```json
{
  "success": true,
  "attachment": {
    "aid": 123,
    "url": "https://your-blog.example/usr/uploads/2026/11/4227869332.jpg",
    "filename": "4227869332.jpg",
    "originalName": "photo.jpg",
    "path": "/usr/uploads/2026/11/4227869332.jpg",
    "size": 8230,
    "mime": "image/jpeg",
    "type": "jpg"
  }
}
```

- `aid`：附件在内容表里的 id；`url`：公开可访问的图片地址，
  可直接写进文章正文（如 `![说明](url)`）。
- `size`：字节数；`mime`：实测 MIME 类型；`type`：扩展名（小写）。
- 返回的 URL 已按站点配置生成（含子目录/反代前缀等，与后台一致）。

错误分支（与其它接口同一错误格式）：

| HTTP 状态 | code | 含义 |
| --- | --- | --- |
| 401 | `unauthorized` | 缺少或错误的 Token（鉴权先于文件校验，无 Token 时即使文件非法也先报 401） |
| 400 | `missing_file` | 没有传 `file` 字段 |
| 400 | `upload_error` | 上传本身出错（PHP 上传错误码） |
| 400 | `file_too_large` | 文件超过 10MB |
| 400 | `bad_file_type` | 扩展名不在白名单，或实测 MIME 与扩展名不符 |
| 400 | `bad_request` | multipart 请求的 `action` 不是 `upload_image`（multipart 只支持上传） |
| 500 | `upload_failed` | 核心上传失败（如该类型不在站点允许的附件类型里） |

注意：JSON 请求体里发 `upload_image` 会报 400（`unknown_action`），
因为它不在 JSON 分发表里——必须用 multipart。

## 明确不做的事（v1.1）

- **删除文章**：没有 `delete_post`。删文章请在后台手动操作。
  （评论删除有接口；文章删除一旦误操作不可恢复，故 v1.1 不提供。）
- **页面（page）**：只管文章（post / post_draft），不管页面。
- **用户、设置、链接、主题/插件、备份、除图片上传外的文件操作**：
  一律没有接口。图片上传是 v1.1 新增的唯一文件操作。
- **自动创建分类**：分类名不存在时报 400，请先在后台建好分类。

## 实现说明

- 路由：`Helper::addAction('muse-api', 'TypechoPlugin\MuseApi\Action')`，
  即 `/index.php/action/muse-api`，与 XML-RPC（`/index.php/action/xmlrpc`）
  同一注册方式。
- 鉴权后以配置的 API 用户临时登录（`Widget\User::simpleLogin`，
  不写 cookie、不写 session），再复用 Typecho 核心组件完成操作，
  因此权限检查、评论数维护、分类/标签同步、别名处理都与后台一致。
- 评论写操作复用 `Widget\Comments\Edit`；
  文章写操作复用 `Widget\Contents\Post\Edit::writePost()`。
- 更新文章时先调 `PostEdit::prepare()` 再调 `writePost()`：
  Typecho 1.3.0 的 XML-RPC 编辑路径漏了 `prepare()`，按其写法更新会
  因为 `have()` 为假而走插入分支产生重复文章，本插件走后台表单同一条
  已验证的编辑路径。
- 另外注意 `Typecho\Widget::widget()` 的 `finally` 里有 `return`，
  会吞掉沙箱回调里抛出的非 Terminal 异常（如 `prepare()` 的 404/403），
  所以写操作都在回调内捕获异常、回调外重新抛出，并且写完后都重新查库
  核对（状态/文本/modified），不只凭“没报错”就当成功。
- 文章列表用核心 Db 查询构造器取 cid 列表（`PostAdmin` 组件没有
  publish/private 筛选，直接用它再客户端过滤会破坏分页），再用
  `Widget\Contents\From` 逐篇取计算字段（链接、分类、标签）。
- 评论列表用 `Widget\Comments\Admin`；`status=all` 时合并三类结果
  再按 coid 倒序分页（核心组件没有“全部”筛选）。
- 图片上传走核心 `Widget\Upload::uploadHandle()`（即后台上传同一条
  路：文件名清洗、扩展名校验、按年月分目录、随机命名、移动文件），
  再用 `Widget\Upload::insert()` 写附件记录。入口按 `Content-Type`
  分流：`multipart/form-data` 只认 `upload_image`，JSON 只走原有
  12 个接口，两边互不干扰。鉴权顺序与 JSON 路径一致（先验 Token、
  再登录 API 用户），文件校验在鉴权之后。
- 与写文章相同的沙箱异常问题也适用于上传：`insert()` 的异常在回调
  内捕获、回调外重新抛出，入库失败时删除已移动的文件，成功后重新
  查库核对附件行（类型与存储路径一致）再返回。

## 测试

- `php -l` 语法检查通过（Plugin.php、Action.php）。
- 在本地 Typecho 1.3.0 + SQLite 全新安装上做过完整冒烟测试
  （非线上站）：激活/停用/重新激活、Token 校验（401）、Token 重新生成
  （旧 Token 立即失效）、13 个接口全部跑通（含草稿、发布、草稿修订、
  密码文章、分类/标签、评论全状态流转、回复、编辑、删除、分页与筛选），
  以及 404/400/405 等错误分支。测试中发现并修复了两个真实问题：
  `Options::plugin()` 返回的是 `Config` 对象不是数组；
  设置表单的 Checkbox 值须用数组，否则 `Request::get()` 会因类型不匹配
  丢弃提交值、导致重新生成失效。
- **v1.1 新增的上传冒烟测试**（同一本地环境，`php -S` 以
  `upload_max_filesize=20M` 启动以便测到插件自己的 10MB 上限）：
  JPEG/PNG/WebP/GIF 四种格式均上传成功，文件落在
  `usr/uploads/2026/11/`，返回的 URL 可直接 GET（200，正确
  Content-Type），附件行 `type=attachment`、`status=publish`、作者为
  API 用户；同一原文件名两次上传得到不同存储名（核心随机命名，不覆盖）。
  错误分支全过：无 Token/错 Token → 401（含“无 Token + 非法文件”
  先报 401，证明鉴权在文件校验之前）；`.txt`、文本冒充 `.jpg` →
  400 `bad_file_type`；11MB 文件 → 400 `file_too_large`；5.8MB 真实
  JPEG → 200，14MB 真实 JPEG → 400 `file_too_large`；缺 `file` 字段 →
  400 `missing_file`；multipart 发 `list_comments` → 400 `bad_request`；
  GET → 405；JSON 发 `upload_image` → 400 `unknown_action`。
  回归：`list_comments`、`list_posts`、`create_post`（草稿）、
  `delete_comment` 均正常；上传后把返回 URL 写进草稿正文再取回也正常。
  另验证：直接覆盖 `Plugin.php` / `Action.php`（不重启、不停用插件）
  后同一 Token 继续有效——升级 v1.1 不需要停用再启用。
- **v1.1.1 补测**（同一本地环境）：模拟 fileinfo 损坏（强制返回
  `application/octet-stream`）后，真 JPEG/PNG/GIF/WebP 仍可上传成功，
  文本冒充 `.jpg` 仍被拒绝；正常路径的上传与回归接口均不受影响。
- 已在真实站点上安装 v1.1.0 并验证：只读接口正常，Token 有效；
  但线上站的 fileinfo 会把真图片认成 `application/octet-stream`，
  导致 v1.1.0 的上传被拒——v1.1.1 已针对性修复，升级后请再试传一张。
  线上 PHP 的 `upload_max_filesize` / `post_max_size` 若小于 10MB，
  会先于插件拦截大文件（此时报错为 `upload_error`），属服务器配置
  而非插件问题。
