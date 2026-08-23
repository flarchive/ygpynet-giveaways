// @ts-nocheck
import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Button from "flarum/common/components/Button";

import GiveawayFormModal from "../components/GiveawayFormModal";

function detectGiveawaySlug(dc): string | null {
  try {
    const content = dc.composer?.fields?.content?.() || "";
    const match = (typeof content === "string" ? content : "").match(
      /\[giveaway slug=([^\s\]]+)/,
    );
    if (match) return match[1];
  } catch (e) {}

  return null;
}

export default function init() {
  extend(
    "flarum/forum/components/DiscussionComposer",
    "headerItems",
    function (items) {
      const dc = this;
      const slug = detectGiveawaySlug(dc);

      items.add(
        "giveaway",
        Button.component(
          {
            className: "DiscussionComposer-changeTags Button Button--ua-reset",
            onclick: () => {
              // 立即弹窗，与官方“选择标签”一致，点击时不再发任何请求
              const isEdit = !!slug;

              app.modal.show(GiveawayFormModal, {
                slug: slug || undefined,
                onsave: (saved) => {
                  if (!saved || !saved.slug) return;

                  if (!isEdit) {
                    const link = `[giveaway slug=${saved.slug}]`;
                    const editor = dc.composer?.editor;

                    if (editor && typeof editor.insertAtCursor === "function") {
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
                  }
                },
              });
            },
          },
          m(
            "span.TagLabel.untagged",
            slug
              ? (app.translator.trans(
                  "ernestdefoe-giveaways.forum.edit_composer_button",
                ) as string)
              : (app.translator.trans(
                  "ernestdefoe-giveaways.forum.composer_button",
                ) as string),
          ),
        ),
        5,
      );
    },
  );
}
