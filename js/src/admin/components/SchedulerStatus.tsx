import app from "flarum/admin/app";
import Component from "flarum/common/Component";
import Alert from "flarum/common/components/Alert";
import type Mithril from "mithril";

const STALE_MS = 15 * 60 * 1000;

/**
 * Admin status banner for the giveaway scheduler. Warns when
 * `flarum:schedule:last_run` is missing or older than 15 minutes, since that
 * means the cron-driven automatic draw may be stalled.
 */
export default class SchedulerStatus extends Component {
  state: "loading" | "ok" | "stale" = "loading";
  lastRun: Date | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    app
      .request<{ scheduleLastRun: string | null }>({
        method: "GET",
        url: app.forum.attribute("apiUrl") + "/giveaways/health",
      })
      .then((res) => {
        this.lastRun = res.scheduleLastRun ? new Date(res.scheduleLastRun) : null;
        this.state =
          !this.lastRun || Date.now() - this.lastRun.getTime() > STALE_MS
            ? "stale"
            : "ok";
        m.redraw();
      })
      .catch(() => {
        this.state = "stale";
        m.redraw();
      });
  }

  view() {
    const t = (k: string, params?: Record<string, unknown>) =>
      app.translator.trans("ernestdefoe-giveaways.admin." + k, params);

    if (this.state === "loading") {
      return <p className="helpText">{t("scheduler_checking") as string}</p>;
    }

    return (
      <Alert type={this.state === "ok" ? "success" : "warning"} dismissible={false}>
        {this.state === "ok"
          ? t("scheduler_ok", { time: this.lastRun!.toLocaleString() })
          : t("scheduler_stale")}
      </Alert>
    );
  }
}