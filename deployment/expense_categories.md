# Owner-managed expense categories

The pinned installer adds `branch_expense_categories` with stable IDs, a unique normalized-name hash, creator and request key. Existing built-in codes and expense records are unchanged. Only persisted primary admin user 1, with no restaurant-bound scope, may POST `branch-expenses.categorySave`. New labels immediately appear in the owner's form and filters and on other devices when they refresh the expense page/data. All authorized branches can use the shared vocabulary; expense records remain branch-scoped.

Repeated submissions of the same normalized name return the existing item. A previously committed request key cannot create a different name. Creation is serialized under the owner record lock and backed by unique constraints. Labels are escaped in HTML, rendered with textContent in the client and included in existing CSV formula-injection protection. No edit/delete operation is introduced, so old expense references and labels remain stable.

The add-category controls preserve the draft expense and can retry an uncertain save by name without duplication. Staff and other managers do not see those controls, and direct POST attempts are forbidden. Verification covers scoped expense creation/filtering, reports/vouchers/exports, replay, stale owner identity, lost browser response and preserved draft.
