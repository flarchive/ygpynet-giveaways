// @ts-nocheck
import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Button from "flarum/common/components/Button";

import GiveawayFormModal from "../components/GiveawayFormModal";
import { listGiveaways } from "../../common/api";

export default function init() {
  extend(
    "flarum/forum/components/DiscussionComposer",
    "headerItems",
    function (items) {
      items.add(
        "giveaway",
        Button.component(
          {
            className: "DiscussionComposer-changeTags Button Button--ua-reset",
            onclick: () => {
              const dc = this;

              listGiveaways().then(({ data: before }) => {
                const slugs = before.map((g) => g.slug);

                app.modal.show(GiveawayFormModal, {
                  onsave: () => {
                    listGiveaways().then(({ data: after }) => {
                      const g =
                        after.find((x) => !slugs.includes(x.slug)) ||
                        after.find((x) => x.status === "active") ||
                        after[0];
                      if (!g) return;

                      const link = `[giveaway slug=${g.slug}]`;
                      // const link = `[🎁 ${g.title}](/giveaways/${g.slug})`;
                      const editor = dc.composer?.editor;

                      if (
                        editor &&
                        typeof editor.insertAtCursor === "function"
                      ) {
                        // 光标处插入，输入框和 composer 内容都会同步更新
                        editor.insertAtCursor(link);
                      } else {
                        const cur = dc.composer.fields.content();
                        dc.composer.fields.content(
                          cur
                            ? cur.replace(/\s+$/, "") + "\n\n" + link.trim()
                            : link.trim(),
                        );
                      }
                      m.redraw();
                    });
                  },
                });
              });
            },
          },
          m(
            "span.TagLabel.untagged",
            app.translator.trans("ernestdefoe-giveaways.forum.composer_button") as string,
          ),
        ),
        5,
      );
    },
  );
}
