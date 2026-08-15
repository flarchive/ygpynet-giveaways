import app from "flarum/forum/app";

export interface GiveawayUser {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
}

export interface GiveawayWinner {
  position: number;
  user: GiveawayUser | null;
  claimedAt: string | null;
}

export interface GiveawayEntrant {
  user: GiveawayUser | null;
  entries: number;
  sources: Record<string, number> | null;
  createdAt: string | null;
}

export interface GiveawayCategory {
  id: number;
  name: string;
  slug: string;
  color: string;
  icon: string | null;
  position?: number;
  count?: number;
}

export interface GiveawayGroup {
  id: number;
  name: string;
  namePlural: string;
  color: string | null;
}

export interface Giveaway {
  id: number;
  title: string;
  slug: string;
  prize: string;
  description: string | null;
  descriptionHtml: string | null;
  coverUrl: string | null;
  winnerCount: number;
  status: "active" | "drawn" | "cancelled";
  startsAt: string | null;
  endsAt: string | null;
  drawnAt: string | null;
  running: boolean;
  entrantCount: number;
  totalEntries: number;
  myEntries: number;
  mySources: Record<string, number> | null;
  myRank: number | null;
  postBonus: number;
  minPosts: number;
  minAgeDays: number;
  canManage: boolean;
  canViewEntries: boolean;
  enterGroups: GiveawayGroup[];
  iWon: boolean;
  myClaimedAt: string | null;
  claimInstructions: string | null;
  createdBy: GiveawayUser | null;
  category: {
    id: number;
    name: string;
    slug: string;
    color: string;
    icon: string | null;
  } | null;
  winners?: GiveawayWinner[];
  drawSeed?: string | null;
  entrantHash?: string | null;
}

export interface ListResult {
  data: Giveaway[];
  meta: {
    canCreate: boolean;
  canManage: boolean;
  canViewEntries: boolean;
    page?: number;
    hasMore?: boolean;
    total?: number;
  };
}

function base(): string {
  return app.forum.attribute("apiUrl") + "/giveaways";
}

export function listGiveaways(page: number = 1): Promise<ListResult> {
  const q = page > 1 ? `?page=${page}` : "";
  return app.request<ListResult>({ method: "GET", url: base() + q });
}

export function showGiveaway(
  idOrSlug: number | string,
): Promise<{ data: Giveaway }> {
  return app.request<{ data: Giveaway }>({
    method: "GET",
    url: `${base()}/${idOrSlug}`,
  });
}

export function enterGiveaway(id: number): Promise<{ data: Giveaway }> {
  return app.request<{ data: Giveaway }>({
    method: "POST",
    url: `${base()}/${id}/enter`,
  });
}

export interface EntriesResult {
  data: GiveawayEntrant[];
  meta: { total: number; page: number; hasMore: boolean };
}

export function listEntries(id: number, page: number = 1): Promise<EntriesResult> {
  const q = page > 1 ? `?page=${page}` : "";
  return app.request<EntriesResult>({
    method: "GET",
    url: `${base()}/${id}/entries` + q,
    errorHandler: () => {},
  });
}

export function drawGiveaway(id: number): Promise<{ data: Giveaway }> {
  return app.request<{ data: Giveaway }>({
    method: "POST",
    url: `${base()}/${id}/draw`,
  });
}

export function claimGiveaway(id: number): Promise<{ data: Giveaway }> {
  return app.request<{ data: Giveaway }>({
    method: "POST",
    url: `${base()}/${id}/claim`,
  });
}

export function deleteGiveaway(id: number): Promise<unknown> {
  return app.request({ method: "DELETE", url: `${base()}/${id}` });
}

export function saveGiveaway(
  attributes: Record<string, unknown>,
  id?: number,
): Promise<{ data: Giveaway }> {
  return app.request<{ data: Giveaway }>({
    method: id ? "PATCH" : "POST",
    url: id ? `${base()}/${id}` : base(),
    body: { data: { attributes } },
  });
}

function catBase(): string {
  return app.forum.attribute("apiUrl") + "/giveaway-categories";
}

export function listCategories(): Promise<{ data: GiveawayCategory[] }> {
  return app.request<{ data: GiveawayCategory[] }>({
    method: "GET",
    url: catBase(),
  });
}

export function saveCategory(
  attributes: Record<string, unknown>,
  id?: number,
): Promise<{ data: GiveawayCategory }> {
  return app.request<{ data: GiveawayCategory }>({
    method: id ? "PATCH" : "POST",
    url: id ? `${catBase()}/${id}` : catBase(),
    body: { data: { attributes } },
  });
}

export function deleteCategory(id: number): Promise<unknown> {
  return app.request({ method: "DELETE", url: `${catBase()}/${id}` });
}
