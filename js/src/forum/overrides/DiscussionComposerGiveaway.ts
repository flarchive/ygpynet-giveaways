// @ts-nocheck
import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Button from "flarum/common/components/Button";

import GiveawayFormModal from "../components/GiveawayFormModal";

let cachedSlug: string | null = null;

function detectGiveawaySlug(dc): string | null {
  try {
    const content = dc.composer?.fields?.content?.() || "";
    const match = (typeof content === "string" ? content : "").match(
      /\[giveaway slug=([^\s\]]+)/,
    );
    if (match) {
      cachedSlug = match[1];
      return match[1];
    }
  } catch (e) {}

  if (cachedSlug) return cachedSlug;
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
              const isEdit = !!slug;

              app.modal.show(GiveawayFormModal, {
                slug: slug || undefined,
                onsave: (saved) => {
                  if (!saved || !saved.slug) return;

                  if (!isEdit) {
                    cachedSlug = saved.slug;
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
