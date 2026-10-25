import { onI18nReady } from "./app";
import statusHelpers from "./status_helpers";

const { normalizeStatusData } = statusHelpers;

const BSSID_PATTERN = /^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/;

function deviceLabel(data) {
  const friendlyName = Array.isArray(data.Status.FriendlyName)
    ? data.Status.FriendlyName[0]
    : data.Status.FriendlyName;

  return (
    data.Status.DeviceName || friendlyName || data.StatusNET.Hostname || ""
  );
}

function collectAccessPoints(result) {
  const accessPoints = new Map();
  let answered = 0;

  Object.values(result || {}).forEach((raw) => {
    if (
      !raw ||
      typeof raw !== "object" ||
      raw.ERROR ||
      raw.WARNING ||
      raw.statusText !== undefined
    ) {
      return;
    }

    const data = normalizeStatusData(raw);
    answered += 1;

    const bssid =
      data.hasWifi && data.wifi && data.wifi.bssid
        ? String(data.wifi.bssid).toUpperCase()
        : "";
    if (!BSSID_PATTERN.test(bssid)) {
      return;
    }

    if (!accessPoints.has(bssid)) {
      accessPoints.set(bssid, []);
    }
    accessPoints.get(bssid).push(deviceLabel(data));
  });

  return { accessPoints, answered };
}

function appendRow(tbody, bssid) {
  const row = document.createElement("tr");
  row.dataset.bssid = bssid;

  const bssidCell = document.createElement("td");
  bssidCell.className = "bssid-value";
  bssidCell.textContent = bssid;

  const nameCell = document.createElement("td");
  const input = document.createElement("input");
  input.type = "text";
  input.className = "form-control";
  input.name = `bssid_names[${bssid}]`;
  input.maxLength = 64;
  input.placeholder = tbody.dataset.placeholder || "";
  input.setAttribute("aria-label", bssid);
  nameCell.appendChild(input);

  const devicesCell = document.createElement("td");
  devicesCell.className = "bssid-devices text-end";
  devicesCell.textContent = "0";

  row.append(bssidCell, nameCell, devicesCell);
  tbody.appendChild(row);

  return row;
}

function sortRows(tbody) {
  [...tbody.querySelectorAll("tr")]
    .sort((a, b) => a.dataset.bssid.localeCompare(b.dataset.bssid))
    .forEach((row) => tbody.appendChild(row));
}

function initBssidNames() {
  const section = document.getElementById("bssid-settings");
  if (!section || !window.sonoff) {
    return;
  }

  const tbody = section.querySelector(".bssid-names-rows");
  const status = section.querySelector(".bssid-scan-status");
  status.textContent = $.i18n("CONFIG_BSSID_SCANNING");

  window.sonoff.getAllStatus(15, function (result) {
    const { accessPoints, answered } = collectAccessPoints(result);

    accessPoints.forEach((devices, bssid) => {
      const row =
        tbody.querySelector(`tr[data-bssid="${bssid}"]`) ||
        appendRow(tbody, bssid);
      const cell = row.querySelector(".bssid-devices");
      cell.textContent = String(devices.length);
      cell.title = devices.filter(Boolean).join(", ");
    });

    sortRows(tbody);
    status.textContent =
      accessPoints.size > 0
        ? $.i18n("CONFIG_BSSID_FOUND", accessPoints.size, answered)
        : $.i18n("CONFIG_BSSID_NONE");
  });
}

onI18nReady(function () {
  initBssidNames();
});

export { collectAccessPoints };
