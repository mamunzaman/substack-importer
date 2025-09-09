jQuery(function ($) {
  const $fetch = $("#substack-fetch");
  const $table = $("#substack-table");
  const $tbody = $table.find("tbody");
  const $import = $("#substack-import"); // bottom button
  const $importTop = $("#substack-import-top"); // top button near Fetch

  // ===== i18n helpers =====
  const I18N =
    window.SubstackImporter && SubstackImporter.i18n
      ? SubstackImporter.i18n
      : {};
  const t = (k, dflt) => (I18N && typeof I18N[k] === "string" ? I18N[k] : dflt);
  const fmt = (str, map) => {
    let out = String(str || "");
    Object.keys(map || {}).forEach((k) => {
      out = out.replaceAll(k, map[k]);
    });
    return out;
  };

  // ===== Material UI Overlay (loading/success/error inline) =====
  function ensureOverlay() {
    if ($("#ssi-overlay").length) return;
    $("body").append(`
      <div id="ssi-overlay" class="ssi-modal-overlay" aria-hidden="true" style="display:none;">
        <div class="ssi-modal" role="dialog" aria-live="polite" aria-busy="true">
          <div class="ssi-modal-body">
            <div class="ssi-loading">
              <div class="ssi-spinner" aria-hidden="true"></div>
              <span class="ssi-text">${t("working", "Working…")}</span>
            </div>
          </div>
        </div>
      </div>
    `);
  }
  function setOverlay(mode, text) {
    ensureOverlay();
    const $ov = $("#ssi-overlay");
    const $loading = $ov.find(".ssi-loading");
    const $spinner = $ov.find(".ssi-spinner");
    const $text = $ov.find(".ssi-text");

    if (mode === "loading") {
      $loading.show();
      $spinner.show();
    } else {
      $loading.hide();
      $spinner.hide();
    }
    $text.text(text || "");
  }
  function showOverlay() {
    ensureOverlay();
    $("#ssi-overlay").fadeIn(120);
  }
  function hideOverlay() {
    $("#ssi-overlay").fadeOut(180);
  }

  // ===== Material UI Popup (variants + actions + a11y) =====
  function ensurePopup() {
    if ($("#ssi-popup").length) return;
    $("body").append(`
      <div id="ssi-popup" class="ssi-modal-overlay" style="display:none;" aria-hidden="true">
        <div class="ssi-modal" role="dialog" aria-modal="true" aria-labelledby="ssi-pop-title" aria-describedby="ssi-pop-desc" tabindex="-1"> 
          <div class="ssi-modal-header">
            <div class="ssi-flex ssi-gap-md">
              <div class="ssi-pop-icon" aria-hidden="true"></div>
              <div>
                <h3 id="ssi-pop-title" style="margin: 0; font-size: var(--md-font-size-h4);"></h3>
                <p id="ssi-pop-desc" style="margin: 4px 0 0; color: var(--md-on-surface-variant); font-size: var(--md-font-size-body2);"></p>
              </div>
            </div>
          </div>
          <div class="ssi-modal-body" id="ssi-pop-body"></div>
          <div class="ssi-modal-footer" id="ssi-pop-actions"></div>
        </div>
      </div>
    `);

    // Backdrop click
    $("#ssi-popup").on("click", function (e) {
      if (e.target.id === "ssi-popup") hidePopup();
    });
    // Close button
    $("#ssi-popup").on("click", ".ssi-pop-close", hidePopup);

    // ESC close
    $(document).on("keydown.ssiPopup", function (e) {
      if ($("#ssi-popup:visible").length && e.key === "Escape") hidePopup();
    });
  }

  // Focus management
  let _prevFocus = null;
  function _trapFocus() {
    const $panel = $(".ssi-pop-panel");
    const $focusables = $panel
      .find(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      )
      .filter(":visible");
    if (!$focusables.length) return;
    const first = $focusables[0],
      last = $focusables[$focusables.length - 1];
    $panel.on("keydown.ssiTrap", function (e) {
      if (e.key !== "Tab") return;
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    });
    (first || $panel[0]).focus();
  }
  function _untrapFocus() {
    $(".ssi-pop-panel").off("keydown.ssiTrap");
    if (_prevFocus) {
      try {
        _prevFocus.focus();
      } catch (_) {}
    }
  }

  function showPopup(config) {
    ensurePopup();
    const opts = Object.assign(
      {
        title: t("actionRequired", "Action required"),
        sub: "",
        message: "",
        variant: "info", // 'success' | 'error' | 'info'
        actions: [
          {
            text: t("ok", "OK"),
            className: "ssi-button ssi-button-primary",
            onClick: hidePopup,
          },
        ],
      },
      config || {}
    );

    const $ov = $("#ssi-popup");
    const $modal = $ov.find(".ssi-modal");
    const $icon = $ov.find(".ssi-pop-icon");
    const $title = $ov.find("#ssi-pop-title");
    const $sub = $ov.find("#ssi-pop-desc");
    const $body = $ov.find("#ssi-pop-body");
    const $acts = $ov.find("#ssi-pop-actions");

    // Set icon based on variant
    $icon.removeClass("is-success is-error is-info");
    if (opts.variant === "success") {
      $icon
        .addClass("is-success")
        .html(
          '<span class="dashicons dashicons-yes-alt" style="color: var(--md-success);"></span>'
        );
    } else if (opts.variant === "error") {
      $icon
        .addClass("is-error")
        .html(
          '<span class="dashicons dashicons-warning" style="color: var(--md-error);"></span>'
        );
    } else {
      $icon
        .addClass("is-info")
        .html(
          '<span class="dashicons dashicons-info" style="color: var(--md-info);"></span>'
        );
    }

    $title.text(opts.title || "");
    $sub.text(opts.sub || "");
    $body
      .html(
        opts.message
          ? `<p style="margin: 0; color: var(--md-on-surface);">${opts.message}</p>`
          : ""
      )
      .toggle(!!opts.message);

    $acts.empty();
    (opts.actions || []).forEach(function (a) {
      const btn = $(
        `<button type="button" class="${
          a.className || "ssi-button ssi-button-primary"
        }">${a.text || t("ok", "OK")}</button>`
      );
      btn.on("click", function () {
        if (typeof a.onClick === "function") a.onClick();
      });
      $acts.append(btn);
    });

    _prevFocus = document.activeElement;
    $ov.fadeIn(120, function () {
      _trapFocus();
      $ov.attr("aria-hidden", "false");
      $modal.addClass("ssi-scale-in");
    });
  }

  function hidePopup() {
    const $ov = $("#ssi-popup");
    const $modal = $ov.find(".ssi-modal");
    $modal.removeClass("ssi-scale-in");
    setTimeout(function () {
      $ov.fadeOut(120, function () {
        $ov.attr("aria-hidden", "true");
        _untrapFocus();
      });
    }, 140);
  }

  // ===== Categories from PHP =====
  const categories = SubstackImporter.categories
    ? JSON.parse(SubstackImporter.categories)
    : [];
  function renderCategorySelect() {
    const opts = categories
      .map((c) => `<option value="${c.id}">${c.name}</option>`)
      .join("");
    return `<select multiple class="substack-cat ssi-select" style="width:100%; min-height: 80px; border-radius: var(--md-radius-sm); border: 1px solid rgba(0, 0, 0, 0.23); padding: var(--md-spacing-sm);">${opts}</select>`;
  }

  function buildRow(item) {
    const status = item.exists
      ? '<span class="ssi-chip ssi-chip-warning">' +
        t("alreadyImported", "Already imported") +
        "</span>"
      : '<span class="ssi-chip ssi-chip-success">' +
        t("new", "New") +
        "</span>";

    const encoded = encodeURIComponent(
      JSON.stringify({
        guid: item.guid || "",
        title: item.title || "",
        date: item.date || "",
        content: item.content || "", // keep full HTML OFF-DOM
        feed_terms: item.feed_terms || [], // include feed terms for import
      })
    );

    return `
      <tr class="substack-row" data-post="${encoded}">
        <td style="text-align: center;">
          <input type="checkbox" class="substack-select" ${
            item.exists ? "disabled" : ""
          } style="transform: scale(1.2);">
        </td>
        <td>
          <div style="font-weight: 500; color: var(--md-on-surface);">
            ${item.title || ""}
          </div>
        </td>
        <td>
          <span style="color: var(--md-on-surface-variant); font-size: var(--md-font-size-body2);">
            ${item.date || ""}
          </span>
        </td>
        <td>
          <div class="ssi-flex ssi-flex-column ssi-gap-xs">
            <span style="font-weight: 500; color: var(--md-on-surface); font-size: var(--md-font-size-caption);">
              WordPress Categories:
            </span>
            ${renderCategorySelect()}
          </div>
        </td>
        <td>${status}</td>
      </tr>`;
  }

  // ===== Fetch feed =====
  $fetch.on("click", function (e) {
    e.preventDefault();
    $fetch
      .prop("disabled", true)
      .html(
        '<span class="dashicons dashicons-update" style="margin-right: 8px; animation: ssi-spin 1s linear infinite;"></span>' +
          t("fetching", "Fetching…")
      );
    $tbody.empty();
    $table.hide();
    $import.hide();
    $importTop.hide();

    $.post(SubstackImporter.ajaxurl, {
      action: "substack_importer_fetch_feed",
      nonce: SubstackImporter.nonce_fetch,
    })
      .done(function (res) {
        if (!res || !res.success) {
          showPopup({
            title: t("actionRequired", "Action required"),
            variant: "error",
            message:
              res && res.data
                ? String(res.data)
                : t("failedFetch", "Failed to fetch feed."),
            actions: [
              {
                text: t("ok", "OK"),
                className: "button button-primary",
                onClick: hidePopup,
              },
            ],
          });
          return;
        }
        if (!res.data || res.data.length === 0) {
          $tbody.html(
            '<tr><td colspan="5">' +
              t(
                "noItems",
                "No items found. Check your feed URLs in Settings."
              ) +
              "</td></tr>"
          );
          $table.show();
          return;
        }
        const rows = res.data.map(buildRow).join("");
        $tbody.html(rows);
        $table.show();
        $import.show();
        $importTop.show();
      })
      .fail(function () {
        showPopup({
          title: t("actionRequired", "Action required"),
          variant: "error",
          message: t("failedFetch", "Failed to fetch feed."),
          actions: [
            {
              text: t("ok", "OK"),
              className: "button button-primary",
              onClick: hidePopup,
            },
          ],
        });
      })
      .always(function () {
        $fetch
          .prop("disabled", false)
          .html(
            '<span class="dashicons dashicons-update" style="margin-right: 8px;"></span>' +
              t("fetch", "Fetch Feed")
          );
      });
  });

  // Top "Import Selected" triggers the same submit as bottom one
  $importTop.on("click", function (e) {
    e.preventDefault();
    $("#substack-import-form").trigger("submit");
  });

  // ===== Import selected — validation then import =====
  $("#substack-import-form").on("submit", function (e) {
    e.preventDefault();

    const items = [];
    let selectedCount = 0;
    let missingCatsCount = 0;

    // clear row highlights
    $tbody.find("tr.substack-row").removeClass("ssi-missing-cat");

    $tbody.find("tr.substack-row").each(function () {
      const $tr = $(this);
      const isChecked = $tr.find(".substack-select").is(":checked");
      if (!isChecked) return;

      selectedCount++;

      const cats = ($tr.find(".substack-cat").val() || []).map((v) =>
        parseInt(v, 10)
      );
      if (cats.length === 0) {
        $tr.addClass("ssi-missing-cat");
        missingCatsCount++;
        return; // skip pushing this item
      }

      let payload = {};
      try {
        payload = JSON.parse(decodeURIComponent($tr.attr("data-post")));
      } catch (e) {
        payload = {};
      }

      items.push({
        title: payload.title || $tr.find("td:nth-child(2)").text(),
        date: payload.date || $tr.find("td:nth-child(3)").text(),
        guid: payload.guid || "",
        content: payload.content || "",
        categories: cats,
        feed_terms: payload.feed_terms || [],
      });
    });

    if (selectedCount === 0) {
      showPopup({
        title: t("actionRequired", "Action required"),
        variant: "error",
        message: t(
          "selectAtLeastOne",
          "Please select at least one post to import."
        ),
        actions: [
          {
            text: t("ok", "OK"),
            className: "button button-primary",
            onClick: hidePopup,
          },
        ],
      });
      return;
    }

    if (missingCatsCount > 0) {
      const msg =
        missingCatsCount === 1
          ? t(
              "selectCatsOne",
              "Select at least one category for the highlighted post."
            )
          : fmt(
              t(
                "selectCatsMany",
                "Select at least one category for %d highlighted posts."
              ),
              { "%d": String(missingCatsCount) }
            );

      showPopup({
        title: t("actionRequired", "Action required"),
        sub: t("fixIssues", "Please fix the issues below and try again."),
        variant: "error",
        message: msg,
        actions: [
          {
            text: t("ok", "OK"),
            className: "button button-primary",
            onClick: hidePopup,
          },
        ],
      });

      // scroll to first highlighted row
      const $firstBad = $tbody.find("tr.ssi-missing-cat").first();
      if ($firstBad.length)
        $("html, body").animate(
          { scrollTop: $firstBad.offset().top - 120 },
          250
        );
      return;
    }

    // proceed with import
    $import
      .prop("disabled", true)
      .html(
        '<span class="dashicons dashicons-download" style="margin-right: 8px; animation: ssi-spin 1s linear infinite;"></span>' +
          t("importing", "Importing…")
      );
    $importTop
      .prop("disabled", true)
      .html(
        '<span class="dashicons dashicons-download" style="margin-right: 8px; animation: ssi-spin 1s linear infinite;"></span>' +
          t("importing", "Importing…")
      );
    $fetch.prop("disabled", true);

    setOverlay("loading", t("importingWait", "Importing… Please wait."));
    showOverlay();

    $.post(SubstackImporter.ajaxurl, {
      action: "substack_importer_import_selected",
      nonce: SubstackImporter.nonce_import,
      items: JSON.stringify(items),
    })
      .done(function (res) {
        // hide loader first
        hideOverlay();

        if (res && res.success) {
          const msgText = fmt(
            t("importResult", "Imported: %1$d · Skipped: %2$d · Errors: %3$d"),
            {
              "%1$d": String(res.data.imported),
              "%2$d": String(res.data.skipped),
              "%3$d": String(res.data.errors),
            }
          );

          // pretty result popup
          showPopup({
            title: t("importCompleteTitle", "Import complete"),
            sub: t(
              "importCompleteSub",
              "Your selected posts have been processed."
            ),
            message: msgText,
            variant: res.data.errors > 0 ? "error" : "success",
            actions: [
              {
                text: t("viewList", "Refresh list"),
                className: "button button-primary",
                onClick: function () {
                  hidePopup();
                  $fetch.trigger("click");
                },
              },
              {
                text: t("close", "Close"),
                className: "button",
                onClick: hidePopup,
              },
            ],
          });
        } else {
          const msg =
            res && res.data
              ? String(res.data)
              : t("importFailed", "Import failed.");
          showPopup({
            title: t("actionRequired", "Action required"),
            variant: "error",
            message: msg,
            actions: [
              {
                text: t("ok", "OK"),
                className: "button button-primary",
                onClick: hidePopup,
              },
            ],
          });
        }
      })
      .fail(function () {
        hideOverlay();
        showPopup({
          title: t("actionRequired", "Action required"),
          variant: "error",
          message: t("importFailed", "Import failed."),
          actions: [
            {
              text: t("ok", "OK"),
              className: "button button-primary",
              onClick: hidePopup,
            },
          ],
        });
      })
      .always(function () {
        $import
          .prop("disabled", false)
          .html(
            '<span class="dashicons dashicons-download" style="margin-right: 8px;"></span>' +
              t("importSelected", "Import Selected")
          );
        $importTop
          .prop("disabled", false)
          .html(
            '<span class="dashicons dashicons-download" style="margin-right: 8px;"></span>' +
              t("importSelected", "Import Selected")
          );
        $fetch
          .prop("disabled", false)
          .html(
            '<span class="dashicons dashicons-update" style="margin-right: 8px;"></span>' +
              t("fetch", "Fetch Feed")
          );
      });
  });

  // ===== Cron Interval Unit Handling =====
  $(document).ready(function () {
    const $intervalInput = $("#ssi-interval");
    const $intervalUnit = $("#ssi-interval-unit");

    function updateIntervalConstraints() {
      const unit = $intervalUnit.val();
      if (unit === "minutes") {
        $intervalInput.attr("min", "2").attr("max", "600");
        $intervalInput.attr("step", "1");
      } else {
        $intervalInput.attr("min", "1").attr("max", "10");
        $intervalInput.attr("step", "1");
      }
    }

    // Update constraints when unit changes
    $intervalUnit.on("change", updateIntervalConstraints);

    // Initialize constraints
    updateIntervalConstraints();

    // Validate input on change
    $intervalInput.on("input", function () {
      const value = parseInt($(this).val());
      const unit = $intervalUnit.val();
      let max = unit === "minutes" ? 600 : 10;
      let min = unit === "minutes" ? 2 : 1;

      if (value < min) {
        $(this).val(min);
      } else if (value > max) {
        $(this).val(max);
      }
    });

    // Cron status refresh functionality
    $("#ssi-refresh-cron-status").on("click", function () {
      const $button = $(this);
      const $content = $("#ssi-cron-status-content");

      $button.prop("disabled", true).text("Refreshing...");

      // AJAX call to refresh cron status
      $.post(SubstackImporter.ajaxurl, {
        action: "substack_importer_refresh_cron_status",
        nonce: SubstackImporter.nonce,
      })
        .done(function (res) {
          if (res && res.success) {
            $content.html(res.data.html);
          }
        })
        .fail(function () {
          // Fallback: just reload the page to get fresh status
          location.reload();
        })
        .always(function () {
          $button.prop("disabled", false).text("Refresh Status");
        });
    });

    // Handle cron checkbox interactions
    const $cronEnabled = $('input[name="substack_importer_cron_enabled"]');
    const $intervalField = $intervalInput.closest(".ssi-form-group");
    const $importLimitField = $(
      'input[name="substack_importer_cron_import_limit"]'
    ).closest(".ssi-form-group");
    const $cronOffsetField = $(
      'input[name="substack_importer_cron_offset"]'
    ).closest(".ssi-form-group");
    const $cronStatusField = $(".ssi-form-group").has(
      "#ssi-cron-status-content"
    );

    function updateCronFieldVisibility() {
      const cronEnabled = $cronEnabled.is(":checked");
      $intervalField.toggle(cronEnabled);
      $importLimitField.toggle(cronEnabled);
      $cronOffsetField.toggle(cronEnabled);
      $cronStatusField.toggle(cronEnabled);
    }

    // Update visibility when cron enabled checkbox changes
    $cronEnabled.on("change", updateCronFieldVisibility);

    // Initialize visibility
    updateCronFieldVisibility();

    // Auto-refresh cron status every minute
    function autoRefreshCronStatus() {
      const $content = $("#ssi-cron-status-content");
      if ($content.length) {
        $.post(SubstackImporter.ajaxurl, {
          action: "substack_importer_refresh_cron_status",
          nonce: SubstackImporter.nonce,
        }).done(function (res) {
          if (res && res.success) {
            $content.html(res.data.html);
          }
        });
      }
    }

    // Start auto-refresh if we're on the settings page
    if ($("#ssi-interval").length) {
      setInterval(autoRefreshCronStatus, 60000); // Refresh every minute
    }

    // Handle reset cron offset button
    $("#ssi-reset-cron-offset").on("click", function () {
      if (
        !confirm(
          "Are you sure you want to reset the cron offset? This will cause the next cron run to start from the beginning of the feed."
        )
      ) {
        return;
      }

      const $button = $(this);
      const originalText = $button.text();
      $button.prop("disabled", true).text("Resetting...");

      $.post(SubstackImporter.ajaxurl, {
        action: "substack_importer_reset_cron_offset",
        nonce: SubstackImporter.nonce_reset_offset,
      })
        .done(function (res) {
          if (res && res.success) {
            // Update the offset field
            $("#ssi-cron-offset").val(0);
            alert(res.data.message);
          } else {
            alert("Failed to reset cron offset. Please try again.");
          }
        })
        .fail(function () {
          alert("Failed to reset cron offset. Please try again.");
        })
        .always(function () {
          $button.prop("disabled", false).text(originalText);
        });
    });
  });

  // ===== Category Mapping Functionality =====
  // Add new mapping row
  $(document).on("click", "#ssi-add-row", function () {
    const $tbody = $("#ssi-term-map-table tbody");
    const $newRow = $(`
      <tr>
        <td><input type="text" name="substack_importer_term_map[label][]" value="" class="regular-text" /></td>
        <td>
          <select name="substack_importer_term_map[type][]" style="min-width:160px">
            <option value="exact">Exact</option>
            <option value="ci">Case-insensitive</option>
            <option value="regex">Regex</option>
          </select>
        </td>
        <td>
          <select name="substack_importer_term_map[term_id][]" style="min-width:200px">
            <option value="0">— Select Category —</option>
            ${
              SubstackImporter.categories
                ? JSON.parse(SubstackImporter.categories)
                    .map(
                      (cat) => `<option value="${cat.id}">${cat.name}</option>`
                    )
                    .join("")
                : ""
            }
          </select>
        </td>
        <td><button type="button" class="button ssi-remove-row">&times;</button></td>
      </tr>
    `);
    $tbody.append($newRow);
  });

  // Remove mapping row
  $(document).on("click", ".ssi-remove-row", function () {
    $(this).closest("tr").remove();
  });

  // Save category mapping
  $(document).on("click", "#ssi-save-mapping", function () {
    const $button = $(this);
    const $form = $("<form>").attr({
      method: "post",
      action: "options.php",
    });

    // Add the form fields
    $form.append('<input type="hidden" name="action" value="update" />');
    $form.append(
      '<input type="hidden" name="option_page" value="substack_importer_settings_group" />'
    );
    $form.append(
      '<input type="hidden" name="_wpnonce" value="' +
        $('input[name="_wpnonce"]').val() +
        '" />'
    );
    $form.append(
      '<input type="hidden" name="_wp_http_referer" value="' +
        $('input[name="_wp_http_referer"]').val() +
        '" />'
    );

    // Add all the mapping fields
    $("#ssi-term-map-table tbody tr").each(function () {
      const $row = $(this);
      const label = $row
        .find('input[name="substack_importer_term_map[label][]"]')
        .val();
      const type = $row
        .find('select[name="substack_importer_term_map[type][]"]')
        .val();
      const termId = $row
        .find('select[name="substack_importer_term_map[term_id][]"]')
        .val();

      if (label && termId && termId !== "0") {
        $form.append(
          '<input type="hidden" name="substack_importer_term_map[label][]" value="' +
            label +
            '" />'
        );
        $form.append(
          '<input type="hidden" name="substack_importer_term_map[type][]" value="' +
            type +
            '" />'
        );
        $form.append(
          '<input type="hidden" name="substack_importer_term_map[term_id][]" value="' +
            termId +
            '" />'
        );
      }
    });

    // Submit the form
    $("body").append($form);
    $form.submit();
  });

  // ===== Standard Checkbox Handling =====
  // Using standard checkboxes instead of custom iOS-style toggles for better compatibility
});
