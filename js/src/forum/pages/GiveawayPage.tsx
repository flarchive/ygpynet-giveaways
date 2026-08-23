import app from "flarum/forum/app";
import Page from "flarum/common/components/Page";
import type Mithril from "mithril";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";
import Button from "flarum/common/components/Button";
import Link from "flarum/common/components/Link";
import Icon from "flarum/common/components/Icon";
import humanTime from "flarum/common/helpers/humanTime";

import {
  showGiveaway,
  enterGiveaway,
  drawGiveaway,
  deleteGiveaway,
  claimGiveaway,
  listEntries,
} from "../../common/api";
import type {
  Giveaway,
  GiveawayEntrant,
  GiveawayGroup,
} from "../../common/api";
import { countdown, isPast, formatDateTime } from "../../common/format";
import GiveawayFormModal from "../components/GiveawayFormModal";

export default class GiveawayPage extends Page {
  loading = true;
  entering = false;
  claiming = false;
  drawing = false;
  giveaway: Giveaway | null = null;
  entrants: GiveawayEntrant[] = [];
  entrantTotal: number | null = null;
  entrantHasMore = false;
  entrantPage = 1;
  tick: number | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history?.push(
      "giveaway",
      app.translator.trans("ernestdefoe-giveaways.forum.page_title"),
      m.route.get(),
    );
    this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    this.tick = setInterval(() => {
      // Keep the countdown live. Once the end time passes locally the box
      // flips to "ended" (server status only changes when the giveaway is
      // drawn), and the timer stops.
      if (!this.giveaway) return;
      m.redraw();
      if (isPast(this.giveaway.endsAt) && this.tick !== null) {
        clearInterval(this.tick);
        this.tick = null;
      }
    }, 1000);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    if (this.tick !== null) {
      clearInterval(this.tick);
      this.tick = null;
    }
  }

  load() {
    const slug = m.route.param("slug");
    this.loading = true;
    showGiveaway(slug)
      .then((res) => {
        this.giveaway = res.data;
        app.setTitle(this.giveaway.title);
        this.loading = false;
        this.entrants = [];
        this.entrantPage = 1;
        this.entrantHasMore = false;
        this.entrantTotal = null;
        if (this.giveaway.canViewEntries) {
          this.loadEntries();
        }
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  loadEntries(reset = false) {
    const g = this.giveaway;
    if (!g) return;
    if (reset) {
      this.entrantPage = 1;
      this.entrants = [];
    }
    listEntries(g.id, this.entrantPage)
      .then((res) => {
        this.entrants = reset ? res.data : [...this.entrants, ...res.data];
        this.entrantTotal = res.meta.total;
        this.entrantHasMore = res.meta.hasMore;
        m.redraw();
      })
      .catch(() => {});
  }

  loadMoreEntries() {
    this.entrantPage += 1;
    this.loadEntries();
  }

  enter() {
    const g = this.giveaway!;
    if (!app.session.user) {
      // LogInModal 是核心懒加载 chunk，必须点击时才通过 asyncModuleImport 加载，
      // 顶层静态 import 会在 chunk 就绪前解析成 undefined。
      app.modal.show(() =>
        flarum.reg.asyncModuleImport("flarum/forum/components/LogInModal"),
      );
      return;
    }
    this.entering = true;
    enterGiveaway(g.id)
      .then((res) => {
        this.giveaway = res.data;
        this.entering = false;
        app.alerts.show(
          { type: "success" },
          app.translator.trans("ernestdefoe-giveaways.forum.enter_success"),
        );
        // 立即刷新参与名单（服务端按时间倒序，自己会出现在最前面）
        this.loadEntries(true);
        m.redraw();
      })
      .catch(() => {
        this.entering = false;
        m.redraw();
      });
  }

  draw() {
    const g = this.giveaway!;
    if (
      this.drawing ||
      !confirm(
        app.translator.trans(
          "ernestdefoe-giveaways.forum.confirm_draw",
        ) as string,
      )
    )
      return;
    this.drawing = true;
    drawGiveaway(g.id)
      .then((res) => {
        this.giveaway = res.data;
        this.drawing = false;
        m.redraw();
      })
      .catch(() => {
        this.drawing = false;
        m.redraw();
      });
  }

  claim() {
    const g = this.giveaway!;
    this.claiming = true;
    claimGiveaway(g.id)
      .then((res) => {
        this.giveaway = res.data;
        this.claiming = false;
        app.alerts.show(
          { type: "success" },
          app.translator.trans("ernestdefoe-giveaways.forum.claim_success"),
        );
        m.redraw();
      })
      .catch(() => {
        this.claiming = false;
        m.redraw();
      });
  }

  edit() {
    app.modal.show(GiveawayFormModal, {
      giveaway: this.giveaway,
      onsave: () => this.load(),
    });
  }

  remove() {
    const g = this.giveaway!;
    if (
      !confirm(
        app.translator.trans(
          "ernestdefoe-giveaways.forum.confirm_delete",
        ) as string,
      )
    )
      return;
    deleteGiveaway(g.id).then(() => m.route.set(app.route("giveaways.index")));
  }

  view(): Mithril.Children {
    if (this.loading)
      return (
        <div className="GiveawayPage">
          <LoadingIndicator />
        </div>
      );
    const g = this.giveaway;
    if (!g) {
      return (
        <div className="GiveawayPage container">
          <p>{app.translator.trans("ernestdefoe-giveaways.forum.empty")}</p>
        </div>
      );
    }

    return (
      <div className="GiveawayPage">
        <div
          className={
            "GiveawayPage-hero" +
            (g.coverUrl ? "" : " GiveawayPage-hero--plain")
          }
          style={
            g.coverUrl ? { backgroundImage: `url("${g.coverUrl}")` } : undefined
          }
        >
          <div className="GiveawayPage-hero-overlay">
            <div className="container">
              <Link
                className="GiveawayPage-back"
                href={app.route("giveaways.index")}
              >
                <Icon name="fas fa-chevron-left" />{" "}
                {app.translator.trans("ernestdefoe-giveaways.forum.nav")}
              </Link>
              <span
                className={`GiveawayCard-status GiveawayCard-status--${g.status}`}
              >
                {app.translator.trans(
                  `ernestdefoe-giveaways.forum.status_${g.status}`,
                )}
              </span>
              {g.category && (
                <span
                  className="GiveawayPage-category"
                  style={{ backgroundColor: g.category.color }}
                >
                  {g.category.icon && <Icon name={g.category.icon} />}{" "}
                  {g.category.name}
                </span>
              )}
              <h1 className="GiveawayPage-title">{g.title}</h1>
              <div className="GiveawayPage-prize">
                <Icon name="fas fa-trophy" /> {g.prize}
              </div>
            </div>
          </div>
        </div>

        <div className="container GiveawayPage-content">
          <div className="GiveawayPage-main">
            {this.winnerBanner(g)}
            {this.descriptionBlock(g)}
            {this.requirementsBlock(g)}
            {this.winnersBlock(g)}
            {this.entrantsBlock(g)}
            {this.fairnessBlock(g)}
          </div>
          <aside className="GiveawayPage-side">
            {this.actionBox(g)}
            {g.canManage && this.manageBox(g)}
          </aside>
        </div>
      </div>
    );
  }

  winnerBanner(g: Giveaway): Mithril.Children {
    if (!g.iWon) return null;
    const claimed = !!g.myClaimedAt;
    return (
      <section className="GiveawayPage-winnerBanner">
        <div className="GiveawayPage-winnerBanner-head">
          <Icon name="fas fa-trophy" />
          <h2>{app.translator.trans("ernestdefoe-giveaways.forum.you_won")}</h2>
        </div>
        <p>
          {app.translator.trans("ernestdefoe-giveaways.forum.you_won_sub", {
            prize: g.prize,
          })}
        </p>
        {claimed ? (
          <div className="GiveawayPage-claimed">
            <Icon name="fas fa-check-circle" />{" "}
            {app.translator.trans("ernestdefoe-giveaways.forum.claimed")}
          </div>
        ) : (
          <Button
            className="Button Button--primary"
            icon="fas fa-box-open"
            loading={this.claiming}
            onclick={() => this.claim()}
          >
            {app.translator.trans("ernestdefoe-giveaways.forum.claim")}
          </Button>
        )}
        {claimed && g.claimInstructions ? (
          <div className="GiveawayPage-claimInstructions">
            <h4>
              {app.translator.trans(
                "ernestdefoe-giveaways.forum.claim_next_steps",
              )}
            </h4>
            {g.claimInstructions
              .split("\n")
              .map((l) => (l.trim() ? <p>{l}</p> : null))}
          </div>
        ) : null}
      </section>
    );
  }

  descriptionBlock(g: Giveaway): Mithril.Children {
    if (!g.description && !g.descriptionHtml) return null;

    return (
      <section className="GiveawayPage-section">
        <h2>
          {app.translator.trans(
            "ernestdefoe-giveaways.forum.description_label",
          )}
        </h2>
        <div className="GiveawayPage-description">
          {g.descriptionHtml && g.descriptionHtml.trim()
            ? m.trust(g.descriptionHtml)
            : g.description
                .split("\n")
                .map((line) => (line.trim() ? <p>{line}</p> : null))}
        </div>
      </section>
    );
  }

  requirementsBlock(g: Giveaway): Mithril.Children {
    const reqs: Mithril.Children[] = [
      <li>
        <Icon name="fas fa-users" /> {this.audienceLabel(g.enterGroups)}
      </li>,
    ];
    if (g.minPosts > 0)
      reqs.push(
        <li>
          <Icon name="fas fa-comment" />{" "}
          {app.translator.trans("ernestdefoe-giveaways.forum.req_min_posts", {
            count: g.minPosts,
          })}
        </li>,
      );
    if (g.minAgeDays > 0)
      reqs.push(
        <li>
          <Icon name="fas fa-hourglass-half" />{" "}
          {app.translator.trans("ernestdefoe-giveaways.forum.req_min_age", {
            count: g.minAgeDays,
          })}
        </li>,
      );

    return (
      <section className="GiveawayPage-section">
        <h2>
          {app.translator.trans(
            "ernestdefoe-giveaways.forum.requirements_label",
          )}
        </h2>
        <ul className="GiveawayPage-reqs">{reqs}</ul>
      </section>
    );
  }

  /** Human description of who may enter, derived from the admin permission grid. */
  audienceLabel(groups: GiveawayGroup[]): string {
    const ids = groups.map((group) => group.id);
    if (ids.includes(2)) {
      // Guests granted → everyone, guests included.
      return app.translator.trans(
        "ernestdefoe-giveaways.forum.open_to_everyone",
      );
    }
    if (ids.includes(3)) {
      // Members granted → all signed-in members.
      return app.translator.trans(
        "ernestdefoe-giveaways.forum.open_to_members",
      );
    }
    // Use each group's singular "member name" (e.g. 管理员), as filled in when
    // the group was created.
    const sep = app.translator.trans(
      "ernestdefoe-giveaways.forum.group_separator",
    ) as string;
    const names = groups.map((group) => group.name);
    if (names.length) {
      return app.translator.trans(
        "ernestdefoe-giveaways.forum.open_to_groups",
        {
          groups: names.join(sep),
        },
      ) as string;
    }
    return app.translator.trans("ernestdefoe-giveaways.forum.open_to_admins");
  }

  /**
   * 真实来路 URL；核心启动时会把首页压栈作为兜底，那不算真实来路。
   */
  realBackUrl(): string | null {
    const prev = app.history?.getPrevious?.();
    if (!prev) return null;
    if (prev.name === "index" && prev.url === "/") return null;
    return prev.url || null;
  }

  /**
   * 中奖概率（%）：
   * - 进行中：已参与 = 我的票数/总票数；未参与按“加入获得基础 1 张票”估算；
   * - 已结束：仍显示实际概率（未参与者不显示，因其概率为 0 无意义）。
   */
  winChance(g: Giveaway): number | null {
    const entered = g.myEntries > 0;
    const over = g.status !== "active" || isPast(g.endsAt);

    if (over) {
      if (!entered || g.totalEntries <= 0) return null;
      return Math.min(100, (g.myEntries / g.totalEntries) * 100);
    }

    const mine = entered ? g.myEntries : 1;
    const total = g.totalEntries + (entered ? 0 : 1);
    if (total <= 0) return null;
    return Math.min(100, (mine / total) * 100);
  }

  entrantsBlock(g: Giveaway): Mithril.Children {
    if (!g.canViewEntries) return null;
    return (
      <section className="GiveawayPage-section">
        <h2>
          {app.translator.trans(
            "ernestdefoe-giveaways.forum.entrants_list_label",
          )}{" "}
          ({this.entrantTotal ?? g.entrantCount})
        </h2>
        {this.entrants.length === 0 ? (
          <p>
            {app.translator.trans("ernestdefoe-giveaways.forum.no_entries")}
          </p>
        ) : (
          <ul className="GiveawayPage-winners GiveawayPage-entrants">
            {this.entrants.map((e) => (
              <li className="GiveawayPage-winner">
                {e.user ? (
                  <Link
                    href={app.route("user", { username: e.user.username })}
                    className="GiveawayPage-winner-user"
                  >
                    <img
                      className="Avatar"
                      src={e.user.avatarUrl || ""}
                      alt=""
                    />
                    <span>{e.user.displayName}</span>
                  </Link>
                ) : (
                  <span className="GiveawayPage-winner-user">—</span>
                )}
                <span className="GiveawayPage-winner-claim">
                  <Icon name="fas fa-ticket-alt" />{" "}
                  {app.translator.trans("ernestdefoe-giveaways.forum.tickets", {
                    count: e.entries,
                  })}
                </span>
                <time
                  className="GiveawayPage-winner-time"
                  datetime={e.createdAt || undefined}
                  title={e.createdAt ? formatDateTime(e.createdAt) : undefined}
                >
                  {e.createdAt ? humanTime(e.createdAt) : ""}
                </time>
              </li>
            ))}
          </ul>
        )}
        {this.entrantHasMore && (
          <Button
            className="Button Button--block"
            onclick={() => this.loadMoreEntries()}
          >
            {app.translator.trans("ernestdefoe-giveaways.forum.load_more")}
          </Button>
        )}
      </section>
    );
  }

  winnersBlock(g: Giveaway): Mithril.Children {
    if (g.status !== "drawn") return null;
    const winners = g.winners || [];
    return (
      <section className="GiveawayPage-section">
        <h2>
          {app.translator.trans("ernestdefoe-giveaways.forum.winners_label")}
        </h2>
        {winners.length === 0 ? (
          <p>
            {app.translator.trans("ernestdefoe-giveaways.forum.no_winners")}
          </p>
        ) : (
          <ul className="GiveawayPage-winners">
            {winners.map((w) => (
              <li className="GiveawayPage-winner">
                <span className="GiveawayPage-winner-pos">#{w.position}</span>
                {w.user ? (
                  <Link
                    href={app.route("user", { username: w.user.username })}
                    className="GiveawayPage-winner-user"
                  >
                    <img
                      className="Avatar"
                      src={w.user.avatarUrl || ""}
                      alt=""
                    />
                    <span>{w.user.displayName}</span>
                  </Link>
                ) : (
                  <span className="GiveawayPage-winner-user">—</span>
                )}
                <span
                  className={
                    "GiveawayPage-winner-claim" +
                    (w.claimedAt ? " is-claimed" : "")
                  }
                >
                  <Icon
                    name={w.claimedAt ? "fas fa-check-circle" : "far fa-clock"}
                  />{" "}
                  {w.claimedAt
                    ? app.translator.trans(
                        "ernestdefoe-giveaways.forum.claimed",
                      )
                    : app.translator.trans(
                        "ernestdefoe-giveaways.forum.unclaimed",
                      )}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    );
  }

  fairnessBlock(g: Giveaway): Mithril.Children {
    if (g.status !== "drawn" || !g.drawSeed) return null;
    return (
      <section className="GiveawayPage-section GiveawayPage-fairness">
        <h2>
          <Icon name="fas fa-shield-alt" />{" "}
          {app.translator.trans("ernestdefoe-giveaways.forum.fairness_label")}
        </h2>
        <p>
          {app.translator.trans("ernestdefoe-giveaways.forum.fairness_intro")}
        </p>
        <div className="GiveawayPage-fairnessField">
          <label>
            {app.translator.trans("ernestdefoe-giveaways.forum.seed_label")}
          </label>
          <code>{g.drawSeed}</code>
        </div>
        <div className="GiveawayPage-fairnessField">
          <label>
            {app.translator.trans("ernestdefoe-giveaways.forum.hash_label")}
          </label>
          <code>{g.entrantHash}</code>
        </div>
        <p className="GiveawayPage-fairnessHelp helpText">
          {app.translator.trans("ernestdefoe-giveaways.forum.fairness_help")}
        </p>
      </section>
    );
  }

  actionBox(g: Giveaway): Mithril.Children {
    const active = g.status === "active" && !isPast(g.endsAt);
    const entered = g.myEntries > 0;

    return (
      <div className="GiveawayPage-actionBox">
        <div className="GiveawayPage-stat">
          <strong>{g.entrantCount}</strong>
          <span>
            {app.translator.trans("ernestdefoe-giveaways.forum.entrants_label")}
          </span>
        </div>

        {g.myRank !== null && (
          <div className="GiveawayPage-stat">
            <strong>{g.myRank}</strong>
            <span>
              {app.translator.trans("ernestdefoe-giveaways.forum.my_rank")}
            </span>
          </div>
        )}

        {this.winChance(g) !== null && (
          <div className="GiveawayPage-stat">
            <strong>
              {(this.winChance(g) as number).toFixed(1)}
              {"%"}
            </strong>
            <span>
              {app.translator.trans(
                entered
                  ? "ernestdefoe-giveaways.forum.win_chance"
                  : "ernestdefoe-giveaways.forum.win_chance_if_join",
              )}
            </span>
          </div>
        )}

        {active ? (
          <div className="GiveawayPage-countdown">
            <Icon name="fas fa-clock" />{" "}
            {app.translator.trans("ernestdefoe-giveaways.forum.ends_in", {
              time: countdown(g.endsAt),
            })}
          </div>
        ) : (
          <div className="GiveawayPage-countdown">
            {app.translator.trans("ernestdefoe-giveaways.forum.ended")}
            {g.drawnAt ? [" · ", humanTime(g.drawnAt)] : null}
          </div>
        )}

        {g.endsAt && (
          <div className="GiveawayPage-countdown">
            <Icon name="fas fa-hourglass-half" />{" "}
            {app.translator.trans("ernestdefoe-giveaways.forum.draw_time")}
            {": "}
            {formatDateTime(g.endsAt)}
          </div>
        )}

        {active &&
          (entered ? (
            <div className="GiveawayPage-entered">
              <Icon name="fas fa-check-circle" />{" "}
              {app.translator.trans(
                "ernestdefoe-giveaways.forum.your_entries",
                { count: g.myEntries },
              )}
            </div>
          ) : !app.session.user ? (
            <Button
              className="Button Button--primary Button--block"
              onclick={() => this.enter()}
            >
              {app.translator.trans(
                "ernestdefoe-giveaways.forum.login_to_enter",
              )}
            </Button>
          ) : (
            <Button
              className="Button Button--primary Button--block"
              icon="fas fa-ticket-alt"
              loading={this.entering}
              onclick={() => this.enter()}
            >
              {app.translator.trans("ernestdefoe-giveaways.forum.enter")}
            </Button>
          ))}

        {active && entered && g.postBonus > 0 && this.earnMore(g)}
      </div>
    );
  }

  earnMore(g: Giveaway): Mithril.Children {
    const sources = g.mySources || {};
    const got = !!sources["post"];
    return (
      <div className="GiveawayPage-earn">
        <h4>{app.translator.trans("ernestdefoe-giveaways.forum.earn_more")}</h4>
        <div className={"GiveawayPage-earnItem" + (got ? " is-done" : "")}>
          <Icon name={got ? "fas fa-check-circle" : "far fa-circle"} />
          {got
            ? app.translator.trans("ernestdefoe-giveaways.forum.earned_post", {
                count: g.postBonus,
              })
            : app.translator.trans("ernestdefoe-giveaways.forum.earn_post", {
                count: g.postBonus,
              })}
        </div>
      </div>
    );
  }

  manageBox(g: Giveaway): Mithril.Children {
    return (
      <div className="GiveawayPage-manage">
        <Button
          className="Button Button--block"
          icon="fas fa-pencil-alt"
          onclick={() => this.edit()}
        >
          {app.translator.trans("ernestdefoe-giveaways.forum.edit")}
        </Button>
        {g.status === "active" && (
          <Button
            className="Button Button--block"
            icon="fas fa-dice"
            loading={this.drawing}
            onclick={() => this.draw()}
          >
            {app.translator.trans("ernestdefoe-giveaways.forum.draw_now")}
          </Button>
        )}
        <Button
          className="Button Button--block GiveawayPage-delete"
          icon="fas fa-trash"
          onclick={() => this.remove()}
        >
          {app.translator.trans("ernestdefoe-giveaways.forum.delete")}
        </Button>
      </div>
    );
  }
}
