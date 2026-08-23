import app from "flarum/forum/app";
import FormModal from "flarum/common/components/FormModal";
import type { IInternalModalAttrs } from "flarum/common/components/Modal";
import type Mithril from "mithril";
import Button from "flarum/common/components/Button";
import Stream from "flarum/common/utils/Stream";

import { saveGiveaway, showGiveaway, listCategories } from "../../common/api";
import type { Giveaway, GiveawayCategory } from "../../common/api";
import LoadingIndicator from "flarum/common/components/LoadingIndicator";

export interface GiveawayFormAttrs extends IInternalModalAttrs {
  giveaway?: Giveaway;
  slug?: string;
  categories?: GiveawayCategory[];
  onsave?: (giveaway?: Giveaway) => void;
}

function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toIso(local: string): string | null {
  if (!local) return null;
  const d = new Date(local);
  return isNaN(d.getTime()) ? null : d.toISOString();
}

export default class GiveawayFormModal extends FormModal<GiveawayFormAttrs> {
  titleInput!: Stream<string>;
  prize!: Stream<string>;
  description!: Stream<string>;
  coverUrl!: Stream<string>;
  endsAt!: Stream<string>;
  startsAt!: Stream<string>;
  winnerCount!: Stream<number>;
  postBonus!: Stream<number>;
  minPosts!: Stream<number>;
  minAgeDays!: Stream<number>;
  categoryId!: Stream<number>;
  claimInstructions!: Stream<string>;
  categories: GiveawayCategory[] = [];
  loadingData = false;
  giveaway?: Giveaway;

  oninit(vnode: Mithril.Vnode<GiveawayFormAttrs>) {
    super.oninit(vnode);
    this.categories = this.attrs.categories || [];
    if (!this.categories.length) {
      listCategories().then((res) => {
        this.categories = res.data || [];
        m.redraw();
      });
    }

    if (this.attrs.giveaway) {
      this.giveaway = this.attrs.giveaway;
      this.fill(this.attrs.giveaway);
    } else if (this.attrs.slug) {
      // 弹窗已打开，后台自行拉取详情，不阻塞 UI
      this.loadingData = true;
      showGiveaway(this.attrs.slug)
        .then(({ data }) => {
          this.loadingData = false;
          this.giveaway = data;
          this.fill(data);
          m.redraw();
        })
        .catch((err) => {
          this.loadingData = false;
          this.onerror(err);
        });
    } else {
      this.fill();
    }
  }

  fill(g?: Giveaway) {
    this.categoryId = Stream(g?.category?.id || 0);
    this.claimInstructions = Stream(g?.claimInstructions || "");
    this.titleInput = Stream(g?.title || "");
    this.prize = Stream(g?.prize || "");
    this.description = Stream(g?.description || "");
    this.coverUrl = Stream(g?.coverUrl || "");
    this.endsAt = Stream(toLocalInput(g?.endsAt));
    this.startsAt = Stream(toLocalInput(g?.startsAt));
    this.winnerCount = Stream(g?.winnerCount || 1);
    this.postBonus = Stream(g?.postBonus || 0);
    this.minPosts = Stream(g?.minPosts || 0);
    this.minAgeDays = Stream(g?.minAgeDays || 0);
  }

  className() {
    return "GiveawayFormModal Modal--large";
  }

  title() {
    return this.giveaway
      ? app.translator.trans("ernestdefoe-giveaways.forum.edit")
      : app.translator.trans("ernestdefoe-giveaways.forum.create");
  }

  content(): Mithril.Children {
    if (this.loadingData) {
      return (
        <div className="Modal-body" style={{ minHeight: "160px" }}>
          <LoadingIndicator size="large" style={{ margin: "48px auto" }} />
        </div>
      );
    }
    const t = (k: string) =>
      app.translator.trans("ernestdefoe-giveaways.forum.form." + k);
    return (
      <div className="Modal-body">
        <div className="Form">
          {this.field(
            t("title_label"),
            <input
              className="FormControl"
              value={this.titleInput()}
              placeholder={t("title_placeholder") as string}
              oninput={(e: Event) =>
                this.titleInput((e.target as HTMLInputElement).value)
              }
            />,
          )}
          {this.field(
            t("prize_label"),
            <input
              className="FormControl"
              value={this.prize()}
              placeholder={t("prize_placeholder") as string}
              oninput={(e: Event) =>
                this.prize((e.target as HTMLInputElement).value)
              }
            />,
          )}
          {this.field(
            t("description_label"),
            <textarea
              className="FormControl"
              rows={4}
              value={this.description()}
              placeholder={t("description_placeholder") as string}
              oninput={(e: Event) =>
                this.description((e.target as HTMLTextAreaElement).value)
              }
            />,
          )}
          {this.field(
            t("cover_label"),
            <input
              className="FormControl"
              value={this.coverUrl()}
              placeholder={t("cover_placeholder") as string}
              oninput={(e: Event) =>
                this.coverUrl((e.target as HTMLInputElement).value)
              }
            />,
          )}
          {this.categories.length > 0 &&
            this.field(
              t("category_label"),
              <select
                className="FormControl"
                value={this.categoryId()}
                onchange={(e: Event) =>
                  this.categoryId(
                    parseInt((e.target as HTMLSelectElement).value, 10) || 0,
                  )
                }
              >
                <option value={0}>{t("category_none")}</option>
                {this.categories.map((c) => (
                  <option value={c.id} selected={this.categoryId() === c.id}>
                    {c.name}
                  </option>
                ))}
              </select>,
            )}

          <div className="GiveawayFormModal-row">
            {this.field(
              t("starts_label"),
              <input
                type="datetime-local"
                className="FormControl"
                value={this.startsAt()}
                oninput={(e: Event) =>
                  this.startsAt((e.target as HTMLInputElement).value)
                }
              />,
            )}
            {this.field(
              t("ends_label"),
              <input
                type="datetime-local"
                className="FormControl"
                value={this.endsAt()}
                oninput={(e: Event) =>
                  this.endsAt((e.target as HTMLInputElement).value)
                }
              />,
            )}
          </div>

          <div className="GiveawayFormModal-row">
            {this.numberField(t("winner_count_label"), this.winnerCount, 1)}
            {this.numberField(
              t("post_bonus_label"),
              this.postBonus,
              0,
              t("post_bonus_help"),
            )}
          </div>
          <div className="GiveawayFormModal-row">
            {this.numberField(t("min_posts_label"), this.minPosts, 0)}
            {this.numberField(t("min_age_label"), this.minAgeDays, 0)}
          </div>
          {this.field(
            t("claim_label"),
            <textarea
              className="FormControl"
              rows={3}
              value={this.claimInstructions()}
              placeholder={t("claim_placeholder") as string}
              oninput={(e: Event) =>
                this.claimInstructions((e.target as HTMLTextAreaElement).value)
              }
            />,
            t("claim_help"),
          )}

          <div className="Form-group">
            <Button
              type="submit"
              className="Button Button--primary"
              loading={this.loading}
            >
              {t("submit")}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  field(
    label: Mithril.Children,
    control: Mithril.Children,
    help?: Mithril.Children,
  ): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{label}</label>
        {control}
        {help && <p className="helpText">{help}</p>}
      </div>
    );
  }

  numberField(
    label: Mithril.Children,
    stream: Stream<number>,
    min: number,
    help?: Mithril.Children,
  ): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{label}</label>
        <input
          type="number"
          className="FormControl"
          min={min}
          value={stream()}
          oninput={(e: Event) =>
            stream(parseInt((e.target as HTMLInputElement).value, 10) || 0)
          }
        />
        {help && <p className="helpText">{help}</p>}
      </div>
    );
  }

  onsubmit(e: Event) {
    e.preventDefault();
    this.loading = true;

    const attrs = {
      title: this.titleInput(),
      prize: this.prize(),
      description: this.description(),
      coverUrl: this.coverUrl(),
      endsAt: toIso(this.endsAt()),
      startsAt: toIso(this.startsAt()),
      winnerCount: this.winnerCount(),
      postBonus: this.postBonus(),
      minPosts: this.minPosts(),
      minAgeDays: this.minAgeDays(),
      categoryId: this.categoryId() || null,
      claimInstructions: this.claimInstructions(),
    };

    saveGiveaway(attrs, this.giveaway?.id)
      .then(({ data }) => {
        this.loading = false;
        app.alerts.show(
          { type: "success" },
          app.translator.trans("ernestdefoe-giveaways.forum.saved"),
        );
        this.hide();
        this.attrs.onsave?.(data);
      })
      .catch((err) => {
        this.loading = false;
        this.onerror(err);
      });
  }

  onremove(vnode: Mithril.VnodeDOM<GiveawayFormAttrs, this>) {
    super.onremove(vnode);

    // 弹窗关闭、焦点陷阱释放后，若焦点回到 composer 内的按钮上，
    // 则把焦点转移到正文编辑器，避免 Composer 残留 active 状态
    setTimeout(() => {
      const active = document.activeElement as HTMLElement | null;
      const editor = document.querySelector<HTMLElement>(
        ".Composer .TextEditor-editor, .Composer textarea",
      );
      if (editor && active && active.closest(".Composer")) {
        editor.focus();
      }
    }, 60);
  }
}
