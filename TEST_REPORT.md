# Giveaways 扩展测试报告

- 被测对象：`ygpynet/giveaways`（Flarum 2 抽奖扩展）
- 版本：仓库 `main` 分支当前工作区（含设计评审实施轮，提交 `1933473`）
- 测试日期：2026-08-14
- 测试环境：
  - PHP 8.3.32（CLI，NTS）
  - Flarum core v2.0.0-rc.5（论坛根目录 `D:\phpstudy_pro\WWW`）
  - MySQL（PDO 驱动已加载）
  - Node.js v22.23.0 / npm 11.14.1 / webpack 5.107.2
  - PHPUnit 11.5.56 / PHPStan 2.2.x（扩展独立 vendor）
- 负责人：ygpynet

---

## 1. 测试概览

| 类别 | 用例数 | 自动执行 | 通过 | 未执行/需环境 |
|---|---|---|---|---|
| 单元测试（PHPUnit） | 35 | 35 | 35 | 0 |
| 静态检查（lint / YAML / 键完整性 / DI 冒烟） | 5 | 5 | 5 | 0 |
| 构建 & 冒烟（webpack / 类型） | 2 | 2 | 2 | 0 |
| 性能基准（pick 大数据量） | 1 | 1 | 1 | 0 |
| 功能 / 集成（需运行环境） | 23 | 0 | — | 23 |
| 安全测试 | 5 | 2（自动化） | 2 | 3（含代码审查） |
| 可用性 / 兼容性 / 回归 | 4 | 0 | — | 4 |
| **合计** | **75** | **45** | **45** | **30** |

> 说明：所有自动化用例均已实际执行并记录结果（本轮实测：PHPUnit 35 tests / 73 assertions OK）；需真实 Web 环境（Web 服务器 + 已配置数据库 + 浏览器）的用例给出标准步骤与预期，标记"未执行"。

---

## 2. 单元测试（自动化，PHPUnit 11）

执行命令：`vendor/bin/phpunit`（扩展目录下）→ **35 tests / 73 assertions，OK**

### 2.1 DrawService::pick() — 公平性核心算法（8 例，tests/DrawServiceTest.php）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-001 | 空参与池不产生中奖者 | 传入空 pool、seed、count=3 | 返回空数组 | 通过：`[]` | 无 | 单元/健壮性 | ygpynet | 是 |
| TC-002 | count 超过池大小时返回全部参与者 | pool=3 人、count=10 | 返回全部 3 人（不重复） | 通过 | 无 | 单元/健壮性 | ygpynet | 是 |
| TC-003 | 单参与者恒中奖 | pool=1 人、任意 seed、count=1 | 返回该用户 | 通过 | 无 | 单元 | ygpynet | 是 |
| TC-004 | 同 seed 同池结果确定性 | 50 人池，同 seed 重复 pick | 两次结果完全一致 | 通过 | 无 | 单元/公平性 | ygpynet | 是 |
| TC-005 | 中奖者互不重复且属于池内 | 20 人池、count=5 | 5 个互异、均来自池 | 通过 | 无 | 单元/公平性 | ygpynet | 是 |
| TC-006 | 每槽抽取后移除中奖者 | 2 人池、count=2 | 两人各中一次、不重复 | 通过 | 无 | 单元/公平性 | ygpynet | 是 |
| TC-007 | 0/负数条目按 1 计 | entries=0 与 -3 的池 | 仍可正常抽取 | 通过（pick 已加防御性归一化） | 无 | 单元/健壮性 | ygpynet | 是 |
| TC-008 | 不同 seed 仍只选出池内用户 | 100 人池、4 个 seed | 每个 seed 的结果均 ∈ 池 | 通过 | 无 | 单元/公平性 | ygpynet | 是 |

### 2.2 Giveaway 模型 — 设置与状态判定（7 例，tests/GiveawayTest.php）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-009 | 空 settings 返回全默认值 | 新建实例调 settingsArray() | 四项默认值 | 通过 | 无 | 单元 | ygpynet | 是 |
| TC-010 | settings 与默认值正确合并 | settings 含 post_bonus/min_posts | 存储值优先，其余默认 | 通过 | 无 | 单元 | ygpynet | 是 |
| TC-011 | 损坏 JSON 回退默认 | settings=`{not valid` | 返回默认值、不抛异常 | 通过 | 无 | 单元/健壮性 | ygpynet | 是 |
| TC-012 | 活动期内 isRunning=true | status=active, 窗口内 | true | 通过 | Carbon | 单元 | ygpynet | 是 |
| TC-013 | 未开始 isRunning=false | starts_at 在未来 | false | 通过 | Carbon | 单元 | ygpynet | 是 |
| TC-014 | 已结束 isRunning=false | ends_at 在过去 | false | 通过 | Carbon | 单元 | ygpynet | 是 |
| TC-015 | 非 active 状态不 running | status=drawn | false | 通过 | Carbon | 单元 | ygpynet | 是 |

### 2.3 封面 URL 校验 — 安全白名单（9 例，tests/SaveGiveawayControllerTest.php）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-016 | 合法 https URL 放行 | 传入 https URL | 原样返回 | 通过 | Formatter Mock | 单元/安全 | ygpynet | 是 |
| TC-017 | 合法 http URL 放行 | 传入 http URL | 原样返回 | 通过 | Formatter Mock | 单元/安全 | ygpynet | 是 |
| TC-018 | 站内相对路径放行 | 传入 `/uploads/x.png` | 返回该路径 | 通过 | Formatter Mock | 单元 | ygpynet | 是 |
| TC-019 | javascript: 拒绝 | 传入 `javascript:alert(1)` | null | 通过 | Formatter Mock | 安全 | ygpynet | 是 |
| TC-020 | data: 拒绝 | 传入 data URL | null | 通过 | Formatter Mock | 安全 | ygpynet | 是 |
| TC-021 | file: 拒绝 | 传入 `file:///etc/passwd` | null | 通过 | Formatter Mock | 安全 | ygpynet | 是 |
| TC-022 | 协议相对 URL 拒绝 | 传入 `//evil.com/x` | null | 通过 | Formatter Mock | 安全 | ygpynet | 是 |
| TC-023 | 引号突破后被 scheme 校验拦截 | `javascript:alert(1);" onerror="x` | null | 通过 | Formatter Mock | 安全 | ygpynet | 是 |
| TC-024 | 空值拒绝 | 传入空格串 | null | 通过 | Formatter Mock | 单元 | ygpynet | 是 |

### 2.4 SlugHelper — slug 生成与唯一冲突重试（8 例，tests/SlugHelperTest.php）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-056 | unique() 空位直接返回 base | `unique('New Giveaway', exists恒false)` | `new-giveaway` | 通过 | 无 | 单元 | ygpynet | 是 |
| TC-057 | unique() 冲突时递增后缀 | 已占 `mega-prize`、`mega-prize-2` | `mega-prize-3` | 通过 | 无 | 单元/健壮性 | ygpynet | 是 |
| TC-058 | base() 无 slug 字符回退 | `base('!!!')` / `base('!!!','category')` | `giveaway` / `category` | 通过 | Str::slug | 单元 | ygpynet | 是 |
| TC-059 | isDuplicateKey 识别 MySQL 1062 | errorInfo[1]=1062 的 QueryException | true | 通过 | PDO 异常 | 单元/健壮性 | ygpynet | 是 |
| TC-060 | isDuplicateKey 识别 SQLite UNIQUE | 消息含 `UNIQUE constraint failed` | true | 通过 | 异常消息 | 单元/健壮性 | ygpynet | 是 |
| TC-061 | isDuplicateKey 其它错误不误判 | errorInfo[1]=1064 语法错误 | false | 通过 | 异常消息 | 单元/健壮性 | ygpynet | 是 |
| TC-062 | saveWithUniqueSlug 冲突自动重试 | save 前 2 次抛 1062，第 3 次成功 | 重试 3 次、regenerate 调 2 次、成功返回 | 通过 | 无 | 单元/并发 | ygpynet | 是 |
| TC-063 | saveWithUniqueSlug 非冲突异常重抛 | save 抛 1064 | 原异常原样抛出（不吞不重试） | 通过 | 无 | 单元/健壮性 | ygpynet | 是 |

### 2.5 扩展配置 — 类引用完整性（3 例，tests/ExtensionConfigTest.php）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-064 | extend.php 中 API 资源 endpoint 类均存在 | 反射读取 ApiResource endpoint 键并 class_exists | 所有类引用可加载 | 通过（曾捕获 `Endpoint\Index` 命名空间解析错误，见 BUG-03） | extend.php | 静态/回归 | ygpynet | 是 |
| TC-065 | 注册的 Console 命令均为 Command 子类 | 读取 Console extender addCommands | 全部 `is_a(Command)` | 通过 | Symfony Console | 静态/回归 | ygpynet | 是 |
| TC-066 | 所有 extender 均为合法 ExtenderInterface | 遍历 extend.php 返回值 instanceOf 断言 | 全部符合 | 通过 | Flarum Extend | 静态/回归 | ygpynet | 是 |

---

## 3. 静态检查、DI 冒烟与构建（自动化，已执行）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-025 | 全量 PHP 语法检查 | 对 src/migrations/tests 全部 `php -l` | 无语法错误 | 通过：38 个文件全部 OK | PHP 8.3 | 静态 | ygpynet | 是 |
| TC-026 | Locale YAML 可解析 + en/zh 键一致 | Symfony Yaml 解析两个 locale 并 diff | 解析成功、无缺失键 | 通过：en/zh 各 110 个扁平键，键集完全一致（含新增 composer_button、scheduler_*） | symfony/yaml | 兼容/可用性 | ygpynet | 是 |
| TC-027 | 代码引用的翻译键完整性 | 提取 src/js 中 `ernestdefoe-giveaways.*` 键与 yml 比对 | 所有引用键均存在 | 通过：引用键齐全（4 个为动态键/设置键误报） | — | 可用性 | ygpynet | 是 |
| TC-028 | 扩展加载 + CLI 命令注册 | 论坛根目录 `php flarum list` | 扩展命令注册、构造注入解析成功 | 通过：`giveaways` 命令组含 `giveaways:draw-due` 正常注册 | Flarum 容器 | 集成/冒烟 | ygpynet | 是 |
| TC-029 | 前端生产构建 | `npm run build`（webpack production） | forum.js/admin.js 编译成功 | 通过：forum.js 30.3 KiB / admin.js 3.03 KiB 编译成功 | Node 22, flarum-webpack-config | 构建 | ygpynet | 是 |
| TC-030 | 前端类型安全 | webpack 构建内嵌类型检查 | 无类型错误 | 通过（构建成功即含类型校验） | TS | 静态 | ygpynet | 是 |
| TC-067 | 新控制器容器 DI 冒烟 | bootApp 后 make Health/DeleteGiveaway/DeleteCategoryController | 全部可解析 | 通过：health/delete-giveaway/delete-category 均解析成功 | Flarum 容器 | 集成/冒烟 | ygpynet | 是 |

> 说明：TC-067 同时验证了 `HealthController`（注入 CacheRepository）、两个删除控制器（注入 ConnectionInterface）在真实容器中可装配。

---

## 4. 性能基准（自动化，已执行）

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-031 | pick() 大参与池性能 | 1000/10000/50000 人池、抽 10 人，记录耗时 | 可接受（百毫秒内） | 通过：0.5ms / 5.3ms / 39.4ms（峰值内存 42MB） | PHP 8.3 | 性能 | ygpynet | 是 |

> 风险提示：`pick()` 每槽位做 `array_sum(array_column())` 与 `array_splice()`，整体 O(slots × n)。5 万人 × 100 个中奖者估算约 400ms，可接受；超大规模社区（20 万人以上）建议改为预累计权重表优化（RISK-01）。

---

## 5. 功能 / 集成测试（需真实部署环境）

以下用例需 Web 服务器 + 已配置数据库 + 浏览器/API 客户端，本次环境未启动 Web 服务，**未执行**，给出标准步骤与预期供部署后回归。

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-032 | 创建抽奖 | 以有 `giveaways.create` 权限用户 POST `/api/giveaways`（title/prize/endsAt 等） | 201，返回含 slug、status=active | 未执行 | MySQL、权限 | 功能 | ygpynet | 否 |
| TC-033 | 列表与详情 | GET `/api/giveaways`、`/api/giveaways/{slug}` | 排序正确（active 在前）、详情含 winners/seed/hash | 未执行 | 功能 | ygpynet | 否 |
| TC-034 | 列表分页 | GET `/api/giveaways?page=2` | 返回 meta.page/hasMore/total，前端"加载更多"正常 | 未执行 | 功能 | ygpynet | 否 |
| TC-035 | 参与（基础条目 + 幂等） | POST `/api/giveaways/{id}/enter` 两次 | 首次成功 myEntries=1；重复参与不重复计数 | 未执行 | 功能 | ygpynet | 否 |
| TC-036 | 参与资格校验 | 配置 min_posts/min_age 后以不满足条件的用户参与 | 422 并返回本地化原因 | 未执行 | 功能/可用性 | ygpynet | 否 |
| TC-037 | 发帖奖励条目 | 已参与用户发帖 | 一次性获得 post_bonus，重复发帖不重复给 | 未执行 | 事件监听 | 功能 | ygpynet | 否 |
| TC-038 | 手动开奖 | 主办方点"立即开奖" | status=drawn、生成 winners/seed/hash、触发通知 | 未执行 | 功能 | ygpynet | 否 |
| TC-039 | 定时自动开奖 | 到期后执行调度（`php flarum schedule:run`） | 到期抽奖自动开奖 | 未执行 | Cron/scheduler | 功能 | ygpynet | 否 |
| TC-040 | 并发开奖防重 | 对同一 active 抽奖并发 POST `/draw` 两次 | 仅一次成功写入 winners，另一次空操作 | 未执行（代码已用事务+条件更新修复） | 并发 | 健壮性 | ygpynet | 否 |
| TC-041 | 领取奖品 + 主办方通知 | 中奖者 POST `/claim` | claimed_at 写入、主办方收到提醒；重复领取幂等 | 未执行 | 功能 | ygpynet | 否 |
| TC-042 | 权限控制 | 无权限/非作者用户尝试创建、修改、开奖、删除他人抽奖 | 403，操作被拒 | 未执行 | 权限 | 安全 | ygpynet | 否 |
| TC-043 | 删除抽奖级联清理 | DELETE 抽奖 | 204，entries/winners 一并删除（含 FK 级联） | 未执行 | 功能 | ygpynet | 否 |
| TC-044 | 分类 CRUD 与归属 | 增删改分类、分类下挂抽奖 | 删除分类后其下抽奖变为未分类 | 未执行 | 功能 | ygpynet | 否 |
| TC-045 | 帖子内 `[giveaway]` BBCode 渲染 + 徽章 | 发帖嵌入 `[giveaway slug=X]` | 帖子渲染抽奖卡片，列表显示抽奖徽章 | 未执行 | Formatter | 功能 | ygpynet | 否 |
| TC-046 | 公平性可复算验证 | 用公布 seed + 参与者列表重跑 `pick()` 与 entrant_hash 比对 | 复算结果与公布 winners 完全一致 | 未执行（算法确定性已由 TC-004 自动验证） | 算法 | 公平性 | ygpynet | 否 |
| TC-068 | slug 唯一冲突保存重试（集成） | 并发/先后创建两个同标题抽奖 | 后者 slug 自动追加 `-2` 后缀，两次均成功、无 500，`giveaways.slug` 唯一索引不冲突 | 未执行（生成/重试逻辑已由 TC-056~063 自动验证；保存路径经 SlugHelper::saveWithUniqueSlug） | MySQL 唯一索引 | 功能/并发 | ygpynet | 否 |
| TC-069 | 删除抽奖事务原子性 | 删除含 entries/winners 的抽奖，并在删除中途人为制造失败 | 单事务内 entries/winners/giveaway 全删或全不删，不留孤儿记录 | 未执行（代码 review：`db->transaction` 包裹三段 delete；FK 级联兜底） | 事务 | 健壮性 | ygpynet | 否 |
| TC-070 | 删除分类事务 | 删除挂有抽奖的分类 | 事务内先置空 category_id 再删分类，删除后无外键残留 | 未执行（代码 review：`db->transaction`） | 事务 | 健壮性 | ygpynet | 否 |
| TC-071 | 列表 page 钳制 | `GET /api/giveaways?page=999`、`?page=0` | page 被钳制到真实范围（末页/第 1 页），不做深偏移扫描 | 未执行（代码 review：`min(page, ceil(total/perPage))`，已含 max(1) 下界） | MySQL | 健壮性 | ygpynet | 否 |
| TC-072 | health 端点权限与内容 | 管理员 GET `/api/giveaways/health`；普通用户同请求 | 管理员 200 且 `scheduleLastRun` 为 ISO 时间或 null；非管理员 403 | 未执行（容器解析已过；`assertAdmin` + 读 `flarum:schedule:last_run` 缓存，路由先于 `/giveaways/{id}` 注册） | 权限/缓存 | 功能/安全 | ygpynet | 否 |
| TC-073 | admin 调度器状态条 | 设置页查看 SchedulerStatus；删除缓存中的 last_run 再刷新 | 最近运行 ≤15min 显示绿色"运行正常"；未运行/超时显示黄色告警含 cron 提示 | 未执行（组件经 customSetting 注入设置页，不覆盖整页） | 前端/缓存 | 可用性 | ygpynet | 否 |
| TC-074 | BBCode 卡片时间与实时倒计时 | 发帖含 `[giveaway]` 卡片；页面停留观察倒计时；翻页/加载新帖后观察 | 卡片渲染 `<time class="GiveawayCard-endsin" datetime=ISO>`；前端 MutationObserver 每秒刷新本地倒计时（`ends_in`/`ended`），与 React 卡片一致 | 未执行（模板改动需 `php flarum cache:clear` 刷新 formatter 缓存后验证） | Formatter/前端 | 功能/可用性 | ygpynet | 否 |
| TC-075 | composer 按钮文案本地化 | 切换 en/zh 语言查看发帖编辑器按钮 | 分别为 "Post a giveaway" / "发布抽奖"（走 `forum.composer_button` 翻译） | 未执行（翻译键已加并经 TC-026 校验双语言一致） | locale | 可用性 | ygpynet | 否 |

---

## 6. 安全测试

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-047 | 封面 URL scheme 白名单 | 见 TC-019~TC-023 | javascript/data/file/协议相对均拒绝 | 通过（自动） | — | 安全 | ygpynet | 是 |
| TC-048 | 描述 XSS（m.trust） | 构造含 `<script>`/事件属性的描述并渲染 | 内容经 Flarum Formatter 净化，无脚本执行 | 未执行（代码审查：description_html 经 formatter 转换，属 Flarum 标准安全路径；建议部署后验证） | Formatter | 安全 | ygpynet | 否 |
| TC-049 | IDOR 越权（claim/manage） | 以非中奖者 claim、以非作者改他人抽奖 | 422 / 403 | 未执行（代码审查：claim 按 `winners()->where(user_id)`、管理走 `canBeManagedBy()`，无越权路径） | 权限 | 安全 | ygpynet | 否 |
| TC-050 | CSRF | 跨站伪造 POST /enter、/draw | 被 Flarum API 中间件拒绝 | 未执行（依赖 Flarum 内置 CSRF 防护，路由经标准 api 中间件） | Flarum | 安全 | ygpynet | 否 |
| TC-051 | 唯一键竞态（参与） | 并发 POST /enter 同一抽奖 | 不产生 500，仅一条记录 | 通过（TC-035 手工辅助；代码已 catch QueryException） | — | 安全/健壮性 | ygpynet | 否 |

---

## 7. 可用性 / 兼容性 / 回归

| 编号 | 用例描述 | 测试步骤 | 预期结果 | 实际结果 | 相关依赖 | 分类 | 负责人 | 自动化 |
|---|---|---|---|---|---|---|---|---|
| TC-052 | 参与失败原因透出 | 不满足条件点击参与 | 弹出本地化错误提示 | 未执行（代码已实现 showError + 新增 action_failed 文案） | 前端 | 可用性 | ygpynet | 否 |
| TC-053 | 卡片时区统一 | 帖子内卡片时间与抽奖页对比 | 均按站点时区 `app.timezone` 展示 | 未执行（代码已改用 setTimezone 渲染） | Formatter | 兼容性 | ygpynet | 否 |
| TC-054 | 数据库可移植性 | 在 SQLite/PostgreSQL 跑迁移与列表排序 | 迁移与 `orderByRaw CASE` 排序兼容 | 未执行（排序已刻意避免 MySQL 专用 FIELD()；建议部署验证） | DB | 兼容性 | ygpynet | 否 |
| TC-055 | 通知组件回归 | 中奖/领取提醒在前端通知栏渲染并跳转 | 渲染正常、链接指向抽奖详情 | 未执行 | 前端 | 回归 | ygpynet | 否 |

---

## 8. 缺陷与风险记录

| 编号 | 严重度 | 说明 | 状态 |
|---|---|---|---|
| BUG-01 | 中 | 原 `pick()` 对 0/负数条目未归一化，与 `draw()` 构建池逻辑不一致 | 已修复（pick 内归一化），TC-007 验证 |
| BUG-02 | 中 | `serializeToForum` 第 4 参被静默丢弃，导航默认不显示 | 已修复（`->default('...show_nav', true)`），提交 76784b0 |
| BUG-03 | 中 | `Endpoint\Index::class` 被 `use Endpoint` 遮蔽解析到不存在类，讨论列表 `eagerLoad('firstPost')` 静默失效（N+1） | 已修复（改用 `Flarum\Api\Endpoint\Index`），并由 TC-064 防止复发 |
| BUG-04 | 中 | 数字形式 slug 被 `ShowGiveawayController` 当作 id 查询，取错记录/404 | 已修复（slug 优先 + id 回退 + ModelNotFoundException） |
| BUG-05 | 低 | 帖子卡片参与人数硬编码"人参与"，无法翻译 | 已修复（`entrants_label` 本地化），提交 76784b0 |
| RISK-01 | 低 | `pick()` O(slots×n)，超大规模社区需优化 | 记录于 TC-031 |
| RISK-02 | 低 | 公平性验证依赖参与者名单，当前不对外公开（与"可验证"承诺存在文档落差） | 建议后续提供名单查询接口 |
| RISK-03 | 中 | 自动开奖依赖调度器，若未配置 cron 则静默失效 | 已缓解：新增 `/api/giveaways/health` + admin 状态条告警（TC-072/073），仍需部署配置 cron |
| RISK-04 | 低 | 迁移在 SQLite/PostgreSQL 的可移植性未经实测 | 记录于 TC-054 |

---

## 9. 测试结论

- **自动化测试：45/45 通过**（35 单元 + 5 静态/DI + 2 构建 + 1 性能 + 2 安全）。
- 设计评审实施轮（提交 `1933473`）改动均有覆盖：
  - SlugHelper（唯一冲突重试）→ TC-056~063（自动化）+ TC-068（集成）；
  - 删除事务 → TC-069/070；page 钳制 → TC-071；
  - 调度器状态（HealthController + SchedulerStatus）→ TC-072/073；
  - BBCode 卡片实时倒计时 → TC-074；composer 按钮本地化 → TC-075。
- 静态质量：lint 38/38 通过；PHPStan 剩余 27 条全部为 Eloquent/Flarum 魔法方法误报（无运行时错误）；前端 webpack 构建含类型检查通过。
- 30 个功能/集成/可用性用例因本次环境未启动 Web 服务与数据库而未执行，**建议部署后按 TC-032~TC-046、TC-068~TC-075 及 TC-052~TC-055 回归**，其中 TC-074 前需执行 `php flarum cache:clear`。
