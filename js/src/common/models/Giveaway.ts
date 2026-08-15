import Model from "flarum/common/Model";

export default class Giveaway extends Model {
  slug() {
    return Model.attribute<string>("slug").call(this);
  }

  title() {
    return Model.attribute<string>("title").call(this);
  }

  prize() {
    return Model.attribute<string>("prize").call(this);
  }

  status() {
    return Model.attribute<string>("status").call(this);
  }

  endsAt() {
    return Model.attribute<Date | null, string | null>(
      "endsAt",
      Model.transformDate,
    ).call(this);
  }

  drawnAt() {
    return Model.attribute<Date | null, string | null>(
      "drawnAt",
      Model.transformDate,
    ).call(this);
  }
}
