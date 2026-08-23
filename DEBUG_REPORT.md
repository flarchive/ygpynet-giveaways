# Giveaways 扩展调试报告

- 被测对象：`ygpynet/giveaways`（Flarum 2 抽奖扩展）
- 版本：`main` 分支当前工作区（提交 `5e34d29`）
- 调试日期：2026-08-14
- 环境：PHP 8.3.32 CLI（无 php-xsl）/ Flarum core v2.0.0-rc.5 / Node 22 / webpack 5.107.2
- 负责人：ygpynet
- 方法：静态审查 + 隔离实验（s9e 解析器实证、容器 DI、CLI 冒烟）+ 回归排除

---

## 1. 发现（存在性）

本轮共定位 **7 处程序错误**（2 中 / 5 低），并排除 5 项先前怀疑点、确认 1 项环境依赖。

| 编号 | 严重度 | 一句话摘要 | 位置 |
|---|---|---|---|
| BUG-A | 中 | 列表接口对"零参与"抽奖仍走逐行查询（N+1 复发） | `GiveawayPresenter.php:49-53,68-70` |
| BUG-B | 中 | 前端用 `status==='active'` 判定"进行中"，过期未开奖的活动仍显示参与/开奖按钮与倒计时 | `GiveawayCard.tsx:16`、`GiveawayPage.tsx:428` |
| BUG-C | 低 | BBCode 卡片链接硬编码根路径 `/giveaways/{slug}`，子目录安装失效 | `GiveawayCardConfigure.php:14` |
| BUG-D | 低 | 帖子渲染时每张卡片单独查一次参与数（Formatter N+1） | `GiveawayCardRender.php:58` |
| BUG-E | 低 | `startsAt` 可晚于 `endsAt`，无校验，产生"永不开始"的活动 | `SaveGiveawayController.php:69-71` |
| BUG-F | 低 | 详情页倒计时只渲染一次，不跳动（与 BBCode 卡片不一致） | `GiveawayPage.tsx:442-448` |
| BUG-G | 低 | MutationObserver 的每秒定时器在卡片被移出 DOM 后不清理（内存泄漏） | `js/src/forum/index.ts:116-117` |

**环境依赖（非扩展缺陷）**：CLI PHP 未安装 php-xsl，s9e XSLT 渲染器无法在本地跑通（`XSLTProcessor not found`）。部署服务器必须启用 php-xsl，否则 `[giveaway]` 卡片与帖子正文无法渲染。

---

## 2. 定位（隔离 / 消除）

### 2.1 BUG-A — 空条目抽奖的 N+1 复发（代码审查 + 逻辑演算）
`GiveawayPresenter::forList()`（`src/Api/GiveawayPresenter.php:49-53`）用
`GiveawayEntry::query()->whereIn('giveaway_id',$ids)->groupBy('giveaway_id')` 聚合参与数。
**隔离点**：GROUP BY 只对"有参与记录"的 giveaway 产出聚合行；`present()`（`:68-70`）在 `$agg` 缺失时回退到
`$g->entries()->count()` 与 `$g->entries()->sum('entries')`。
**演算**：页面 100 条、若全部零参与 → 额外 200 次查询。这正是 forList 当初要消灭的 N+1，被回退逻辑部分复活。

### 2.2 BUG-B — status 与 running 混用（交叉验证 API 输出）
Presenter 已向 API 输出服务端判定的 `running`（`Giveaway.php:88-94` 的 `isRunning()`）。
**隔离点**：前端 `GiveawayCard.tsx:16` 与 `GiveawayPage.tsx:428` 却用 `g.status === 'active'`。
**演算**：抽奖到期但调度器未跑（cron 缺失时常见）→ 数据库 `status` 仍为 `active` 但 `isRunning()` 为 false。
此时列表/详情仍渲染"参与 / 立即开奖"按钮与倒计时；点击参与被服务端 422 拒绝，用户看到"已结束"错误。

### 2.3 BUG-C — 卡片链接硬编码（模板审查）
`GiveawayCardConfigure.php:14` 模板写死 `href="/giveaways/{@slug}"`（根相对路径，无 base path、不经过
`app.route()`/URL 生成器）。`GiveawayCardRender` 有能力改写 href 但未做。子目录安装（如 `https://host/forum`）下点击卡片跳错地址。

### 2.4 BUG-D — 渲染阶段 N+1（审查 + 隔离）
`GiveawayCardRender.php:26-30` 用 `whereIn('slug', $m[1])` 批量取活动（优），但 `:58` 对每张卡执行
`$g->entries()->count()`。一页多卡 → 每卡一次查询。

### 2.5 BUG-E — 起止时间未校验（审查）
`SaveGiveawayController.php:59-67` 校验 `ends_at` 必填与未来值，`:69-71` 直接赋值 `starts_at`，两者无先后约束。
`starts_at > ends_at` 时 `isRunning()` 恒 false（`Giveaway.php:90-93`），活动永不开始、到点即被 `DrawDueCommand` 开奖。

### 2.6 BUG-F — 详情页倒计时静态（审查）
`GiveawayPage.tsx:442-448` 每次渲染用 `countdown(g.endsAt)` 计算一次；与 BBCode 卡片（`index.ts:94-119` 每秒 tick）不一致。

### 2.7 BUG-G — 定时器泄漏（审查）
`index.ts:116-117` 每张卡 `setInterval(tick,1000)` 并挂 `_gvEndsinTick`；Observer（`:129-136`）只在新增卡片时重扫，元素被移除（离开页面/翻页替换）后 interval 不清理，持续 tick 于已脱离文档的节点。

---

## 3. 原因（根因分析）

| 编号 | 根因 |
|---|---|
| BUG-A | 聚合查询未覆盖零行集合（缺 LEFT JOIN / withCount 兜底），present 的"缺省回退"设计掩盖了问题 |
| BUG-B | 服务端已有 `running` 语义，前端未采用，UI 状态判定与业务状态（起止窗口）脱节 |
| BUG-C | 模板直接写静态 URL，绕过 Flarum 的 URL 生成/基路径处理 |
| BUG-D | 渲染钩子批量取活动却逐卡取统计，统计未并入批量查询 |
| BUG-E | 控制器仅做单字段校验，缺跨字段约束 |
| BUG-F | 详情页未接入与卡片一致的定时刷新机制 |
| BUG-G | 定时器生命周期未与 DOM 生命周期绑定，Observer 不感知删除 |

---

## 4. 解决（纠正方案，均为建议，未实施）

| 编号 | 建议方案 |
|---|---|
| BUG-A | 列表查询改为 `Giveaway::query()->withCount('entries')->withSum('entries','entries')` 一次性带出零行统计，或聚合 SQL 改为以 giveaways 为左表；present 直接读 `entries_count/entries_sum`，删除逐行回退 |
| BUG-B | 前端判定改为 `g.running`（`GiveawayCard.tsx:16`、`GiveawayPage.tsx:428` 及 actionBox 内的倒计时/按钮显隐）；卡片按 `running` 显示"进行中倒计时"、非 running 显示"已结束" |
| BUG-C | 在 `GiveawayCardRender::card()` 中生成 `href`（`$this->url->to('forum')->route('giveaways.show', ['slug'=>$g->slug])`）作为新属性注入模板，模板改为 `href="{@cardhref}"` |
| BUG-D | 批量查询加 `->withCount('entries')`，卡片用 `$g->entries_count`，去掉 `:58` 的逐卡查询 |
| BUG-E | `SaveGiveawayController` 增加 `starts_at && ends_at && starts_at->gt(ends_at)` → 422（本地化文案） |
| BUG-F | 详情页用 `setInterval` 每秒 `m.redraw()` 或复用统一的倒计时 tick，与卡片一致 |
| BUG-G | 在 Observer 中同时监听 removal，元素移出时 `clearInterval`；或将 interval 改为单实例按卡片集合刷新 |

---

## 5. 改正与重测（计划，未实施）

> 依任务要求仅报告，未对代码做任何改动。以下为修复后应执行的验证：

1. **BUG-A**：修复后对"含 0 参与 + N 参与"的列表跑 `php flarum api` 场景或单元测试，断言 `entrantCount/totalEntries` 正确且**查询数恒定**（可用 Eloquent `DB::listen` 计数，期望恒 ≤3）。
2. **BUG-B**：构造"到期未开奖"（status=active, ends_at 过去）的活动，验证列表卡片与详情页不再显示参与/开奖按钮、倒计时显示"已结束"；点击不产生 422。
3. **BUG-C**：子目录安装下点击帖子内卡片，URL 含基路径且可导航；`href` 与 `app.route('giveaways.show')` 一致。
4. **BUG-D**：含 10 张卡片的帖子渲染，断言参与数查询合并（`DB::listen` 计数不随卡片数线性增长）。
5. **BUG-E**：提交 `startsAt > endsAt` → 422 且数据库未写入。
6. **BUG-F**：详情页停留观察倒计时每秒递减。
7. **BUG-G**：进入/离开含卡片的页面多次，浏览器 `setInterval` 句柄计数不增长（可用 devtools 计时器探查）。
8. **回归**：`vendor/bin/phpunit`（当前 35/35）、`npm run build`、`php flarum list` 全量通过；在启用 php-xsl 的 Web 环境重跑 TC-045/TC-074（BBCode 卡片渲染与倒计时）。

---

## 6. 排查范围与排除项（消除法记录）

| 怀疑点 | 结论 | 依据 |
|---|---|---|
| composer 按钮 `dc.composer` 未定义导致崩溃 | **排除（非缺陷）** | Flarum 2 `ComposerBody` 内置 `this.composer`（`DiscussionComposer.js:34,107` 同用法）；`insertAtCursor`/`fields.content()` 路径均有效 |
| `Extend.Admin().customSetting` API 不存在 | **排除** | 核心 `Admin.ts:66` 签名 `customSetting(setting, priority=0)` 匹配 |
| `HealthController` 的 `assertAdmin()` 不存在 | **排除** | 核心 `User.php:557` 定义存在 |
| `config['app.timezone']` 为空导致 `setTimezone(null)` 崩溃 | **排除** | `Config::defaults()` 默认 `['app'=>['timezone'=>'UTC']]`，`Arr::get` 兜底为 UTC；实测 `setTimezone(null)` 才抛 TypeError，但该路径不可达 |
| composer 插入的 `[giveaway slug=X]`（无引号）无法被 formatter 解析 | **排除** | 隔离实验：s9e 解析 `[giveaway slug=my-giveaway-2]` → `<GIVEAWAY slug="my-giveaway-2">`，slug 捕获正确；带引号/带空格（`slug=two words` 被整体捕获但无匹配活动，安全）均不报错 |
| 调度器 `Console::schedule` 签名串 | **排除** | 容器/CLI 冒烟通过，`giveaways:draw-due` 正常注册 |
| 渲染模板 `<time datetime="{@endsin_iso}">` 语法 | **排除（部分）** | s9e `finalize()` 模板规范化通过（无 XSLT 语法错误）；实际渲染因 CLI 缺 php-xsl 未能本地复现，需部署环境确认 |
| 通知 subject 序列化（GiveawayResource） | **排除** | 资源类型已注册且无 HTTP 端点，与注释设计一致 |

---

## 7. 结论

- 未发现会阻断核心流程（创建/参与/开奖/领取）的致命错误；5 项先前怀疑点经实证全部排除。
- 需修复的真问题集中在**性能 N+1（BUG-A/BUG-D）、状态语义不一致（BUG-B）与边界健壮性（BUG-C/E/F/G）**，其中 BUG-A、BUG-B 建议优先处理。
- 部署前提：Web 服务器 PHP 需启用 php-xsl（当前 CLI 无该扩展，s9e 卡片渲染无法本地验证，见 TC-045/TC-074）。
- 本报告不包含任何代码改动；修复与重测按第 5 节计划执行。
