"use strict";
document.addEventListener("DOMContentLoaded", async () => {
  const avatar = document.querySelector(".user-card .avatar");
  if (!avatar) return;
  try {
    const account = await fetch("account.php").then((r) => r.json());
    if (!account.ok) return;
    const button = document.createElement("button");
    button.className = "avatar";
    button.type = "button";
    button.title = "Сменить аватарку";
    button.setAttribute("aria-label", "Сменить аватарку");
    button.textContent = avatar.textContent;
    const showImage = () => {
      const img = document.createElement("img");
      img.src = "account.php?image=1&v=" + Date.now();
      img.alt = "Моя аватарка";
      button.replaceChildren(img);
    };
    avatar.replaceWith(button);
    if (account.exists) showImage();
    const dialog = document.createElement("dialog");
    dialog.className = "cab-avatar-dialog";
    dialog.innerHTML =
      '<form><h2>Моя аватарка</h2><label>PNG, JPEG или WebP, до 2 МБ<input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required></label><p role="alert"></p><div class="cab-actions"><button type="button">Отмена</button><button class="primary" type="submit">Сохранить</button></div></form>';
    document.body.append(dialog);
    button.onclick = () => dialog.showModal();
    dialog.querySelector("[type=button]").onclick = () => dialog.close();
    dialog.querySelector("form").onsubmit = async (event) => {
      event.preventDefault();
      const submit = dialog.querySelector("[type=submit]");
      submit.disabled = true;
      try {
        const data = new FormData(event.target);
        data.append("csrf", account.csrf);
        const r = await fetch("account.php", {
          method: "POST",
          body: data,
        }).then((r) => r.json());
        if (!r.ok) throw new Error(r.error);
        showImage();
        dialog.close();
      } catch (e) {
        dialog.querySelector("[role=alert]").textContent = e.message;
      } finally {
        submit.disabled = false;
      }
    };
  } catch {}
});
document.querySelectorAll(".cab-filters select").forEach((select) =>
  select.addEventListener("change", () => {
    if (select.form.method.toLowerCase() === "get") select.form.requestSubmit();
  }),
);

// На телефоне обычные списки превращаются в карточки; журналы сохраняют сетку.
document
  .querySelectorAll(".sec-table, .head-table:not(.head-matrix), .cab-table")
  .forEach((table) => {
    const labels = Array.from(table.querySelectorAll("thead th"), (cell) =>
      cell.textContent.trim(),
    );
    if (!labels.length) return;
    table.dataset.mobileCards = "1";
    table.querySelectorAll("tbody tr").forEach((row) => {
      Array.from(row.children).forEach((cell, index) => {
        cell.dataset.label = labels[index] || "Действия";
        const value = document.createElement("div");
        value.className = "mobile-cell-value";
        value.append(...cell.childNodes);
        cell.append(value);
      });
    });
  });

document.querySelectorAll(".cab-day-nav summary").forEach((summary) => {
  summary.addEventListener("click", (event) => {
    event.preventDefault();
    const details = summary.parentElement;
    details.open = !details.open;
    if (!details.open) return;
    const input = details.querySelector("input[type=date]");
    try {
      input.showPicker();
    } catch {
      input.focus();
    }
  });
});

// Фильтры используют одинаковый сброс во всех кабинетах.
document
  .querySelectorAll(".filter-toolbar, .cab-filters")
  .forEach((container) => {
    if (
      container instanceof HTMLFormElement &&
      container.method.toLowerCase() !== "get"
    )
      return;
    container.querySelectorAll("button").forEach((button) => {
      if (["Показать", "Найти"].includes(button.textContent.trim()))
        button.remove();
    });
    const existingReset = container.querySelector(".filter-reset");
    if (existingReset?.tagName === "A") return;
    const reset = existingReset || document.createElement("button");
    reset.type = "button";
    reset.className = "cab-button filter-reset";
    reset.textContent = "Сбросить фильтры";
    reset.onclick = () => {
      if (!(container instanceof HTMLFormElement)) {
        container
          .querySelectorAll("select")
          .forEach((select) => (select.value = ""));
        if (typeof onSemesterChange === "function") onSemesterChange();
        return;
      }
      const url = new URL(location.pathname, location.origin);
      container.querySelectorAll("input[type=hidden]").forEach((input) => {
        if (["tab", "group"].includes(input.name))
          url.searchParams.set(input.name, input.value);
      });
      location.assign(url);
    };
    container.append(reset);
  });
document
  .querySelectorAll(".cab-filters input[type=number]")
  .forEach((input) => {
    input.addEventListener("change", () => {
      if (input.form.method === "get") input.form.requestSubmit();
    });
  });
document.querySelectorAll('.grade').forEach(badge=>{
  if(badge.textContent.trim().length>1)badge.dataset.textResult='1';
});
