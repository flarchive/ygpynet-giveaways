# ygpynet/giveaways 扩展设计文档

> 适用于 Flarum 2 的可验证公平（provably-fair）抽奖/赠品扩展。
> 本文档描述总体架构与领域设计，并从**扩展性、健壮性、可用性、安全性、模块化**五个维度阐述设计决策与演进方向。

---

## 1. 设计目标

| 目标 | 说明 |
|---|---|
| 核心场景 | 用户在论坛创建抽奖 → 会员一键参与 → 到期自动/手动开奖 → 中奖者收通知并领奖 |
| 公平性 | 开奖结果可被任何第三方用公开的 seed + entrant hash 复算验证 |
| 生态集成 | 可选接入积分系统扣费/退款、发帖奖励额外参与数、与讨论帖联动 |
| 管理体验 | 草稿、分类、细粒度权限、后台调度状态可视化 |

## 2. 总体架构

采用 **分层 + 端口/适配器（Hexagonal）** 结构：

```
┌─────────────────────────────────────────────────────────┐
│ 前端层  js/src/forum (React 页面/组件/通知)  js/src/admin │
├─────────────────────────────────────────────────────────┤
│ 接入层  extend.php: 路由 / API Controllers / Console     │
│         Api\Controller\*  Api\Resource\GiveawayResource  │
│         Console\DrawDueCommand (schedule:run 每分钟)     │
├─────────────────────────────────────────────────────────┤
│ 领域/服务层  EntryService  DrawService  CancelService    │
│         RefundService（唯一退款通道+失败入队）           │
│         Formatter\*  Listener\*  Notification\*  Event\* │
├─────────────────────────────────────────────────────────┤
│ 模型层  Giveaway / GiveawayEntry / GiveawayWinner        │
│         GiveawayCategory / GiveawayRefund                │
│         （状态机 + 关系 + 授权规则）                      │
├─────────────────────────────────────────────────────────┤
│ 端口(Contract)  WinnerPicker ◄── HashWeightedPicker      │
│                 PointsGateway ◄─ RamonPointSystemGateway │
│                 EligibilityRule ◄─ MinPosts/MinAge/      │
│                     PointsBalance（tag 集合，可注入）     │
└─────────────────────────────────────────────────────────┘
```

### 2.1 数据模型（migrations/）

```
giveaways          1 ── n giveaway_entries   (unique(giveaway_id,user_id), FK 级联删除)
       │           1 ── n giveaway_winners   (position, claimed_at)
       ├── n:1 giveaway_categories (id, name, slug, color, icon)
       └── user_id → users (主办方, 可空)

giveaway_entries: entries(加权参与数), paid_amount(参与时实扣积分, 资金审计), sources JSON
giveaway_refunds : 失败退款队列 (giveaway_id, user_id, amount, reason, attempts, last_error, refunded_at)
                   无外键 —— 审计记录必须活过抽奖帖/账号的删除

giveaways 关键字段:
  status        draft | active | drawn | cancelled
  starts_at / ends_at / drawn_at
  winner_count  1..100
  settings      JSON {post_bonus, min_posts, min_age_days,
                      entry_cost_points, claim_instructions}
  draw_seed     开奖随机种子（公开）
  entrant_hash  sha256("user_id:entries,..." 按 user_id 排序)（公开）
  source / discussion_id  与讨论帖联动
```

`settings` 用 JSON 扩展列而非逐字段迁移，使"参与方式/资格条件"可持续增加而不破坏表结构；已存在的 `sources` JSON（`{base:1, post:2, ...}`）同理支持任意奖励来源。

### 2.2 状态机（src/Giveaway.php）

```
draft ──publish──► active ──draw()──► drawn      (terminal)
  │                   │
  └────cancel─────────┴──cancel()──► cancelled   (terminal)
```

- 转移表集中在 `Giveaway::TRANSITIONS`，`canTransitionTo()` 统一校验；
- `drawn`/`cancelled` 为**不可变发布记录**：`isEditable()` 拒绝再编辑，防止"改写历史"；
- `active → drawn` 只允许由 `DrawService` 完成（它携带公平性账本 seed/hash 的写入）。

### 2.3 核心流程

**参与（EntryService::enter）**
1. 控制器先查 `ineligibleReason()`（结构性：登录、时间窗；规则性：`EligibilityRule` tag 集合 —— 最低发帖数、账号年龄、积分余额、第三方门槛）；
2. 若收积分：**先扣费**（积分系统可能不在同一 DB 连接，无法入事务）；
3. 进入事务：`lockForUpdate` 锁 giveaway 行 → 锁内**重新校验**时间窗 → 写 entry（含 `paid_amount` 实付记账）；
4. 任何失败 → 立即经 `RefundService` 退款（即时退款也失败则入 `giveaway_refunds` 队列）；唯一约束冲突（并发重复参与）→ 返回已有行而非 500；
5. 事务提交后派发 `GiveawayWasEntered` 事件。

**开奖（DrawService::draw）**
1. 原子认领 `UPDATE ... WHERE status='active'` —— 手动开奖与调度器并发时只有一方成功，杜绝双开奖；
2. 同一事务内：排序序列化参与者名单 → sha256 entrant_hash → `random_bytes(16)` seed → `WinnerPicker::pick(pool, seed, count)` → 写 winners + seed/hash/drawn_at；
3. 提交后：派发 `GiveawayWasDrawn`、逐个发中奖通知（try/catch 尽力而为，通知失败绝不回滚已完成的开奖）。

**公平算法（HashWeightedPicker）**：对每个中奖席位 `pos i`，用 `sha256(seed:i)` 归约到加权池选一人，移除后继续；(pool, seed) 纯函数 → 任何人可复算。

**取消（CancelService::cancel）**：原子认领状态后按每个 entry 的 `paid_amount`（实付额）逐个退款；失败不致命也不丢失——`RefundService` 落 `giveaway_refunds` 队列，由 `giveaways:retry-refunds`（调度器每 15 分钟）排空；派发 `GiveawayWasCancelled`。

**公平验证（giveaways:verify）**：`DrawVerifier` 与 `DrawService` 共享同一 fingerprint/pool 实现，重算 entrant_hash 与 seed 复算中奖名单，比对落库 winners，退出码标示 VERIFIED / TAMPER。

**自动开奖**：`flarum schedule:run`（crontab 每分钟）→ `giveaways:draw-due`，`withoutOverlapping()`。

## 3. 五要素设计说明

### 3.1 扩展性（Extensibility）

| 扩展点 | 机制 |
|---|---|
| 抽奖算法 | `Contract\WinnerPicker` 接口 + DI 单例绑定，其他扩展在 ServiceProvider 中 `singleton()` 重绑即可换算法，DrawService 零改动 |
| 积分系统 | `Contract\PointsGateway` 软桥接 ramon/point-system；未安装时 `available()=false`，收费抽奖自动禁入而非崩溃；可换任意积分实现 |
| 奖励来源 | `EntryService::addBonus($giveaway,$user,$source,$n)` 是公开 API：第三方可授予任意命名来源的加成参与数（每来源一次性、锁内合并 JSON） |
| 资格门槛 | `Contract\EligibilityRule` tag 集合：默认 MinPosts / MinAccountAge / PointsBalance 三个规则，第三方 `$container->tag(MyRule::class, EligibilityRule::class)` 注入新门槛，EntryService 零改动 |
| 领域事件 | `Event\GiveawayWas{Entered,Drawn,Claimed,Cancelled}` 全部在状态持久化后派发，供外部监听（发帖、统计、Webhook） |
| 讨论帖集成 | Formatter 自定义 `[giveaway slug=...]` BBCard（Configure/Render）+ `DiscussionResource` 追加 `hasGiveaway` 字段与已结束标题前缀，均为增量扩展不碰核心 |
| UI 扩展 | forum 侧以组件覆盖（DiscussionComposerGiveaway 等）与通知蓝图注册，遵循 Flarum 前端扩展惯例 |

### 3.2 健壮性（Robustness）

- **并发安全是本设计的核心不变量**：
  - 状态变更一律"原子 UPDATE + WHERE status IN (...)"认领（draw / cancel / delete）；
  - 参与写入在行锁事务内二次校验窗口，堵死"开奖竞态产生幽灵参与记录、破坏 entrant_hash 可验证性"的洞；
  - `addBonus` 与 `enter` 同序加锁（先 giveaway 行、后 entry 行）：开奖提交的补偿必须被拒绝——否则哈希发布后池权重被改，诚实开奖会被 verify 判为 TAMPER（调试轮修复）；
  - 删除活体抽奖 = 原子认领为 cancelled → 事务内收集实付证据 → 提交后退款（失败入队）——删除不再蒸发积分（调试轮修复）；
  - `GiveawayWasDrawn` 只为真正完成开奖的 worker 派发，竞态败者必须静默（调试轮修复）；
  - 唯一索引 `(giveaway_id,user_id)` + QueryException 兜底 → 重复参与幂等。
- **副作用分级**：DB 强一致操作进事务；跨连接（积分）与尽力而为（通知）放事务外，失败只记日志不回滚核心状态。
- **外部输入防御**：`Carbon::parse` 包 try/catch；分页 `page` 钳制防深偏移扫描；`max(1,(int))`、`mb_substr` 长度钳制贯穿；enter/draw 端点 per-actor 限流（缓存固定窗口，缓存故障降级放行）。
- **资金补偿闭环**：参与失败即时退款、取消按实付额（`paid_amount`）退款、失败自动入 `giveaway_refunds` 队列、`giveaways:retry-refunds` 定时排空——资金操作没有"只进日志"的死路。
- **降级行为明确**：调度器未运行 → 可手动开奖；积分系统缺失 → 免费抽奖不受影响，收费抽奖给出可理解的禁入原因。
- **测试**：`tests/Unit` 覆盖纯逻辑（picker 确定性/分布、slug、DrawVerifier 规范化、Throttle 窗口、EligibilityRule 各门槛）；`tests/Integration`（flarum/testing，进程隔离）覆盖端到端不变量——双开奖原子认领、锁内复检拒绝幽灵参与、按实付退款、失败退款入队并由命令排空、限流 429、verify VERIFIED/TAMPER；phpstan level 配置在 `phpstan.neon`，新增文件零错误（基线的 Eloquent magic 误报待 larastan 根治）。

### 3.3 可用性（Usability）

- 独立 `/giveaways` 与 `/giveaways/{slug}` 页面：卡片、实时倒计时、状态徽标、分类色标与过滤 pills；
- 一键参与，按钮实时显示自己的参与数与来源明细（mySources）与排名（myRank）；
- 错误信息全部**本地化且具体**（未开始/已结束/已开奖/已取消/帖子数不足/账号太新/积分不足各有文案），`ineligibleReason()` 集中产生；
- 中奖 → 站内通知直达页面 → "You won!" 横幅 + 一键 Claim + 分席位领奖说明（多中奖者按行分配 instructions）；
- 草稿机制：未发布仅作者/管理员可见；后台 SchedulerStatus 组件让管理员确认自动开奖是否在运转；
- 完整 i18n（en / zh-Hans），导航入口可配置开关。

### 3.4 安全性（Security）

- **授权集中**：`Giveaway::canBeManagedBy()` 一处定义"manage 全局权限 或 作者+create 权限"，presenter 与所有 controller 共用，避免规则漂移；
- **权限矩阵**（migrations/2026_06_06_000004 起）：`giveaways.enter` / `giveaways.create`（ own ）/ `giveaways.manage`（ all ）/ `giveaways.viewEntries`，默认最小授权（enter=Members，其余=Admin）；
- 入口一律 `assertRegistered()` + `assertCan()`；guest 永远查不到他人草稿与参与名单（ListGiveaways 的 draft 过滤、ListEntries 的 viewEntries 权限**同样受草稿私密性约束**——调试轮补齐：持 viewEntries 的成员也无法枚举他人 draft 的参与名单）；
- **XSS/注入面**：description 经 Flarum Formatter 白名单清洗后存储为 `description_html`，前端只渲染清洗产物；分类 color/icon 受控格式；slug 由 `SlugHelper` 规范化生成；
- **cover_url 双保险**（SaveGiveawayController::url）：剥除可逃逸 CSS `url("...")` 的字符 + 仅允许 http/https 或站内相对路径，封死 `javascript:`/`data:`；
- **公平性即防篡改**：seed 用 `random_bytes`（CSPRNG）；开奖后记录不可变（isEditable 拦截）；entrant_hash 使运营方无法事后塞人/改权重；`giveaways:verify` 提供服务端自助复算；
- **滥用抑制**：enter（30/分）与 draw（10/分）按 actor 限流，超限 429；防止对收费抽奖的高频扣费重试与开奖轰炸；
- 参数全部整型化/长度截断（title/prize 255、description 20k、winner_count 1..100、settings 非负整数）；
- SQL 可移植（orderByRaw CASE 而非 MySQL FIELD()），无字符串拼接用户输入。

### 3.5 模块化（Modularity）

```
src/
  Api/         Controller/ Resource/ Presenter   ← 接入层，仅薄胶水
  Contract/    对外端口（稳定的抽象，供第三方依赖）
  Support/     默认实现 + 无状态工具（Picker/Gateway/Slug/Lookup）
               Eligibility/  默认资格门槛规则（依赖 Contract\EligibilityRule）
  Event/       领域事件（公开 API 的一部分）
  Listener/    对核心事件的反应（发帖奖励、帖-抽奖关联）
  Notification/蓝图
  Console/     CLI + 调度（draw-due / retry-refunds / verify）
  Formatter/   富文本卡片
  *.php        模型 + 领域核心 Service（Entry/Draw/Cancel/Refund）
```

- 依赖方向单一：Controller → Service → Model/Contract；Service 不依赖 PSR-7，可在 CLI/测试中复用；
- 每类只暴露必要公共 API；`DrawService::pick` 已标记 `@deprecated` 薄委托，展示 BC 演进策略；
- 前端按 `common / forum / admin` 三入口拆分，`api.ts`、model、组件分层；
- 软依赖（ramon/point-system）放 `suggest` + 运行时 `available()` 探测，不产生硬 require；
- 请求级缓存 `GiveawaySlugLookup` 单点收敛列表页的 slug 反查。

## 4. 已知权衡与改进路线（实施状态）

> 本节最初记录的权衡已全部落地，实现细节见下。历史权衡"取消退款按当前 `entry_cost_points` 退"已被消除。

| # | 演进项 | 状态 | 实现 |
|---|---|---|---|
| 1 | 资金审计 | ✅ 已实现 | `giveaway_entries.paid_amount` 列（迁移 000005，含旧数据按当时费用回填）；`RefundService` 成为唯一退款通道：即时失败落入 `giveaway_refunds` 队列表（迁移 000006，无外键的审计表），`giveaways:retry-refunds` 命令排空（已接入调度器每 15 分钟）。`CancelService` 改为按每人**实付额**退款 |
| 2 | 防滥用 | ✅ 已实现 | `Support\Throttle`（缓存固定窗口，缓存故障时降级放行）；`enter` 30 次/分、`draw` 10 次/分，超限返回 429 + `giveaways.rate_limited` 本地化文案 |
| 3 | 可观测性 | ✅ 已实现 | `HealthController` 新增 `pendingDraws`、`oldestDueAt`、`nextDueAt`、`pointsAvailable`、`pendingRefunds`（保持 admin 前端向后兼容） |
| 4 | 扩展点下沉 | ✅ 已实现 | `Contract\EligibilityRule` 接口；`min_posts` / `min_age_days` / 积分余额抽为 `Support\Eligibility\*` 默认实现，经 **tag 容器**注入 `EntryService`；第三方 `$container->tag(MyRule::class, EligibilityRule::class)` 即可加门槛。结构性门槛（登录、时间窗）保留在 `EntryService` |
| 5 | 验证工具 | ✅ 已实现 | `giveaways:verify {id}` 命令 + `Support\DrawVerifier`（fingerprint/pool 与 `DrawService` 共享同一实现，杜绝"验证器与开奖器不一致"）；退出码区分已验证/篡改 |
| 6 | 测试补强 | ✅ 已实现 | 单元测试 29 例（Throttle/DrawVerifier/EligibilityRules/Picker/Slug）+ **flarum/testing 集成套件** 28 例（tests/Integration/）：双开奖竞态（stale 模型二次 draw 必须空转、seed 不变）、开奖 vs 参与竞态（幽灵参与被锁内复检拒绝且不落库）、收费参与资金账本（paid_amount、退款按实付非当前费用、即时退款失败入队、retry 命令排空）、幂等参与、奖励来源一次性、限流 429 边界与 per-actor 隔离、health 诊断字段、verify 命令 VERIFIED/TAMPER 双向 |

遗留（明确不做/暂缓）：

- `DrawService::pick()` 的 `@deprecated` 委托保留至下一大版本；
- 取消/退款金额漂移问题因 `paid_amount` 记账已不复存在，但**参与后立即改费再取消**的旧行回填仍是近似值（迁移注释已声明）；
- phpstan level 5 基线非零（Eloquent magic 误报，需 larastan 扩展根治），新增文件零错误；
- 集成测试是**单连接**的确定性交错模拟（旧模型 + 原子认领复现竞态结局），非真多线程并发；真并发正确性由"原子 UPDATE 认领 + 行锁"在 DB 层保证，未被负载测试覆盖。

## 5. 测试基础设施

```
composer test              # 单元 + 集成
composer test:unit         # 纯单元测试（无外部依赖）
composer test:setup        # 一次性安装 flarum/testing 用的一次性 forum（仅测试库！）
composer test:integration  # 集成套件（tests/Integration/，进程隔离）
```

- 集成套件默认 SQLite；本机 PHP 无 `pdo_sqlite` 时用 `DB_DRIVER=mysql` 等环境变量指向一个**专用测试库**（SetupScript 会 drop 该库所有表）；
- 积分系统由 `MemoryPointsGateway`（+`SwapPointsGateway` extender）替换——顺带验证了 §3.1 的 PointsGateway 扩展点真实可换；`FailingAwardGateway` 注入退款失败以测试排队/重试；
- 每个测试在事务中运行并回滚（flarum/testing 内建），互不污染。

## 6. 新增运维命令

```sh
php flarum giveaways:verify <id>        # 复算并验证一次开奖的公平性
php flarum giveaways:retry-refunds      # 重试失败的积分退款（调度器每 15 分钟自动执行）
```

---

*文档版本对应代码库：migrations 截至 2026_09_15（settings 前缀 `ygpynet-giveaways.`）。*
