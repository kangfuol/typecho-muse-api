# MuseApi —— Typecho 评论 / 文章管理 API（Token 鉴权）

给 Muse（或其他可信客户端）用的最小化管理接口，让 Typecho 博客
能像 WordPress 那样走 API 托管评论和文章。

- **只做两件事**：管理评论、管理文章。编辑级权限。
- **不做**：用户管理、设置修改、链接、主题/插件、备份、文件操作。
- **v1 没有删除文章接口**：删文章请在后台手动操作（见下方说明）。
- **鉴权**：静态 Token，放在 `X-Muse-Token` 请求头里（不在 URL 里传）。
- **要求**：Typecho 1.3.0，PHP 8.1+（代码用了 PHP 8.1 的 `never` 返回类型）。

## 安装

1. 把 `MuseApi` 文件夹（内含 `Plugin.php`、`Action.php`）上传到
   `usr/plugins/`，即 `usr/plugins/MuseApi/Plugin.php`、
   `usr/plugins/MuseApi/Action.php`。
2. 后台 → 插件 → 找到 **MuseApi** → 启用。
   启用时自动生成一个 48 字符的随机 Token 并注册接口路由。
3. 后台 → 插件 → MuseApi 的「设置」，复制 **API Token**，
   存入凭证库（只存凭证库，不要发到聊天记录、不要放进 URL）。
4. 设置里还有 **API 用户名**（默认 `muse`），接口以该用户的身份执行
   操作，该用户必须是编辑或管理员。

接口地址：`POST https://your-blog.example/index.php/action/muse-api`

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

| HTTP 状态 | 含义 |
| --- | --- |
| 400 | 请求体不是 JSON 对象、未知的 action、参数不合法 |
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

## 明确不做的事（v1）

- **删除文章**：没有 `delete_post`。删文章请在后台手动操作。
  （评论删除有接口；文章删除一旦误操作不可恢复，故 v1 不提供。）
- **页面（page）**：只管文章（post / post_draft），不管页面。
- **用户、设置、链接、主题/插件、备份、文件操作**：一律没有接口。
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

## 测试

- `php -l` 语法检查通过（Plugin.php、Action.php）。
- 在本地 Typecho 1.3.0 + SQLite 全新安装上做过完整冒烟测试
  （非线上站）：激活/停用/重新激活、Token 校验（401）、Token 重新生成
  （旧 Token 立即失效）、12 个接口全部跑通（含草稿、发布、草稿修订、
  密码文章、分类/标签、评论全状态流转、回复、编辑、删除、分页与筛选），
  以及 404/400/405 等错误分支。测试中发现并修复了两个真实问题：
  `Options::plugin()` 返回的是 `Config` 对象不是数组；
  设置表单的 Checkbox 值须用数组，否则 `Request::get()` 会因类型不匹配
  丢弃提交值、导致重新生成失效。
- 已在真实站点上验证了只读接口和评论删除，安装后请先用只读接口
  （`list_posts`、`list_comments`）验证，再试写操作。
