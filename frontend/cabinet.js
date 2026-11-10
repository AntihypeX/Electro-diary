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
      '<form><h2>Моя аватарка</h2><label>PNG, JPEG или WebP, до 2 МБ<span class="cab-file-control"><input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required></span></label><p role="alert"></p><div class="cab-actions"><button type="button" class="cab-button">Отмена</button><button class="primary" type="submit">Сохранить</button></div></form>';
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
  const date = summary.parentElement.querySelector('input[type=date]');
  date?.addEventListener('change', () => { if (date.value) date.form.requestSubmit(); });
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
document.querySelectorAll(".grade").forEach((badge) => {
  if (badge.textContent.trim().length > 1) badge.dataset.textResult = "1";
});

// Сведения ведомости редактируются отдельно от быстрой панели отметок.
document.addEventListener("click", async (event) => {
  const button = event.target.closest("[data-result-details]");
  if (!button) return;
  const record = JSON.parse(button.dataset.resultDetails);
  const dialog = document.createElement("dialog");
  dialog.className = "cab-avatar-dialog";
  dialog.innerHTML =
    '<form class="record-details-form"><h2>Сведения о результате</h2><p class="record-context"></p><label>Номер ведомости<input name="statement_number" maxlength="100"></label><label>Дата выставления<input type="date" name="assessed_on"></label><p role="alert"></p><div class="cab-actions"><button type="button" class="cab-button">Отмена</button><button type="submit" class="primary">Сохранить</button></div></form>';
  const form = dialog.querySelector("form");
  dialog.querySelector(".record-context").textContent =
    record.student + " · " + record.subject + " · " + record.kind;
  for (const [name, value] of Object.entries(record.details))
    form.elements[name].value = value ?? "";
  dialog.querySelector("[type=button]").onclick = () => dialog.close();
  let saving = false;
  dialog.addEventListener("cancel", (event) => {
    if (saving) event.preventDefault();
  });
  dialog.addEventListener("close", () => {
    dialog.remove();
    button.focus({ preventScroll: true });
  });
  form.onsubmit = async (event) => {
    event.preventDefault();
    if (saving) return;
    saving = true;
    dialog.querySelectorAll("button").forEach((b) => (b.disabled = true));
    try {
      const account = await fetch("account.php").then((r) => r.json());
      const payload = {
        ...record.key,
        action: "result_details",
        grade: record.key.previous,
        expectedDetails: record.details,
      };
      for (const name of ["statement_number", "assessed_on"])
        payload[name] = form.elements[name].value;
      const body = new FormData();
      body.append("csrf", account.csrf);
      body.append("payload", JSON.stringify(payload));
      const response = await fetch(location.href, {
        method: "POST",
        headers: { Accept: "application/json" },
        body,
      });
      const result = await response.json();
      if (!response.ok || !result.ok)
        throw new Error(result.error || "Не удалось сохранить.");
      location.reload();
    } catch (e) {
      saving = false;
      dialog.querySelector("[role=alert]").textContent = e.message;
      dialog.querySelectorAll("button").forEach((b) => (b.disabled = false));
    }
  };
  document.body.append(dialog);
  dialog.showModal();
});

// Смена формы контроля показывает отдельную сохранённую запись этого вида.
document.querySelectorAll('[data-schedule-form]').forEach(form => {
  const subject = form.elements.subject;
  const topic = form.elements.topic;
  function updateTopics() {
    topic.value = '';
    const entry = subject.selectedOptions[0]?.dataset;
    form.elements.teacher_display.value = entry?.teacher ?? '';
    form.elements.hours_display.value = entry?.hours ?? '';
    form.elements.control_display.value = entry?.control ?? '';
    for (const option of topic.options) {
      const visible = !option.value || option.dataset.subject === subject.value;
      option.hidden = !visible;
      option.disabled = !visible;
    }
  }
  subject.addEventListener('change', updateTopics);
  updateTopics();
});

// Длинные таблицы и списки сохраняют первые десять строк в видимой области.
function limitVisibleItems(viewport, items, content) {
  if (items.length <= 10) return;
  viewport.classList.add('cab-list-scroll');
  if (!viewport.hasAttribute('tabindex')) viewport.tabIndex = 0;
  if (!viewport.hasAttribute('aria-label')) {
    viewport.setAttribute('aria-label', 'Прокручиваемый список');
  }
  function measure() {
    if (!viewport.offsetWidth) return;
    const last = items[9].getBoundingClientRect();
    const top = viewport.getBoundingClientRect().top;
    const scrollbar = viewport.offsetHeight - viewport.clientHeight;
    const maxHeight = Math.ceil(last.bottom - top + viewport.scrollTop + scrollbar);
    if (maxHeight > 0) viewport.style.maxHeight = maxHeight + 'px';
  }
  const observer = new ResizeObserver(measure);
  observer.observe(content);
  viewport.closest('details')?.addEventListener('toggle', measure);
  measure();
}

document.querySelectorAll('.app-shell table').forEach(table => {
  const rows = Array.from(table.tBodies).flatMap(body => Array.from(body.rows));
  if (rows.length <= 10) return;
  let viewport = table.parentElement;
  if (!viewport.matches('.cab-scroll, .matrix-container, .head-scroll, .sec-table-wrap, .table-wrap')) {
    viewport = document.createElement('div');
    table.before(viewport);
    viewport.append(table);
  }
  limitVisibleItems(viewport, rows, table);
});
document.querySelectorAll('.teacher-lesson-list').forEach(list => {
  limitVisibleItems(list, Array.from(list.children), list);
});
