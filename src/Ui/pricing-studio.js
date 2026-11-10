// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

"use strict";

(() => {
  const $ = (id) => document.getElementById(id);
  const productNames = {
    cloud: "LibreSign Cloud",
    managed: "LibreSign Managed",
    customer_infrastructure: "LibreSign na infraestrutura do cliente",
  };
  const productHelp = {
    cloud: "Venda sem interação humana. Infraestrutura compartilhada dimensionada por assinaturas e consumo, com operação central de plataforma e aquisição de clientes. Nenhum custo de suporte individual foi incluído.",
    managed: "Mesmo núcleo de plataforma, mas sem repetir a hospedagem compartilhada. A capacidade é dedicada por contrato. Gestão, atualizações e suporte configurado somam custos adicionais.",
    customer_infrastructure: "A hospedagem é do contratante, portanto não é cobrada aqui. Implantação, horas de suporte e autorização de acesso remoto são consideradas separadamente. Os créditos do SaaS não são impostos a este modelo.",
  };
  const errorMap = {
    unauthorized: "Credencial de analista inválida.",
    economic_matrix_unavailable: "O catálogo econômico não está configurado ou tem unidades inválidas.",
    invalid_economic_input: "Confira os limites e os campos desta modalidade. Parâmetros econômicos também podem tornar a hipótese inviável.",
    invalid_input: "Os campos da solicitação não passaram pela validação do servidor.",
    rate_limited: "Limite de solicitações atingido. Aguarde antes de tentar novamente.",
  };
  let matrix = null;
  let model = "cloud";
  let busy = false;
  let creditFactorCatalog = [];
  let economicCatalog = [];
  let pendingRecalculation = null;
  let lastRequestAt = 0;
  let lastSuccessfulSignature = null;
  let changedAt = 0;
  const initialPrices = { cloud: "39.00", managed: "1200.00", customer_infrastructure: "400.00" };
  const pricesByModel = {...initialPrices};
  const creditResources = ["signed_obligations", "users", "storage_gb", "api_calls", "tsa_documents"];
  const sliders = {
    accounts: [1, 10000, 1], "candidate-monthly": [0, 5000, 5],
    "annual-discount": [0, 50, 1], included: [0, 5000, 10],
    "topup-units": [1, 2000, 10], "topup-price": [0, 500, 1],
    "take-rate": [0, 100, 1], "acquisition-rate": [0, 100, 1],
    "managed-support": [0, 100, 1], "managed-setup": [0, 100, 1],
    "managed-setup-price": [0, 30000, 100],
    "onprem-support": [0, 100, 1], "onprem-setup": [0, 100, 1],
    "onprem-setup-price": [0, 30000, 100],
    "usage-signed_obligations": [0, 1500, 1],
    "usage-users": [0, 500, 1], "usage-storage_gb": [0, 2000, 10],
    "usage-api_calls": [0, 10000, 100], "usage-tsa_documents": [0, 1500, 1],
  };


  const set = (id, text) => { $(id).textContent = String(text); };
  const labelMessage = (text, error = false) => {
    set("feedback", text);
    $("feedback").classList.toggle("error", error);
  };

  function formatMoney(raw) {
    if (raw === null || raw === undefined) return "Não se aplica";
    const value = String(raw);
    if (!/^-?[0-9]+(?:\.[0-9]{1,2})?$/.test(value)) return "—";
    const neg = value.startsWith("-");
    const [intPart, frac = ""] = (neg ? value.slice(1) : value).split(".");
    return (neg ? "−" : "") + "R$ " +
      intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".") + "," + frac.padEnd(2, "0");
  }

  function localized(raw) {
    return raw === null || raw === undefined ? "—" : String(raw).replace(".", ",");
  }

  function moneyInput(id) {
    const raw = $(id).value.trim();
    if (!raw) return null;
    if (!/^(?:0|[1-9][0-9]{0,8})(?:[.,][0-9]{1,2})?$/.test(raw)) {
      throw new Error("Confira " + $(id).previousElementSibling.textContent + ": informe preço em reais sem separadores de milhar.");
    }
    return raw.replace(",", ".");
  }

  function hoursInput(id) {
    const raw = $(id).value.trim().replace(",", ".");
    if (!/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/.test(raw)) {
      throw new Error("Confira as horas. Utilize valores de 0 a 999 com até duas casas decimais.");
    }
    return raw;
  }

  function intInput(id, min, max) {
    const raw = $(id).value.trim();
    if (!/^(?:0|[1-9][0-9]*)$/.test(raw)) throw new Error("Informe um inteiro válido em " + id + ".");
    const value = Number(raw);
    if (!Number.isSafeInteger(value) || value < min || value > max) throw new Error("O campo " + id + " está fora do limite.");
    return value;
  }

  function rateInput(id) {
    const raw = $(id).value.trim().replace(",", ".");
    if (!/^(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,2})?$/.test(raw)) {
      throw new Error("Informe percentuais entre 0 e 100, com até duas casas decimais.");
    }
    const [integer, fraction = ""] = raw.split(".");
    const scaled = Number(integer) * 100 + Number(fraction.padEnd(2, "0"));
    if (scaled > 10000) throw new Error("O percentual não pode superar 100%.");
    if (scaled === 10000) return "1";
    return "0." + String(scaled).padStart(4, "0");
  }

  async function api(url, method = "GET", payload = null) {
    const token = $("token").value.trim();
    if (!token) throw new Error("Informe a credencial para acessar os dados internos.");
    let response;
    try {
      response = await fetch(url, {
        method,
        headers: {
          Authorization: "Bearer " + token,
          Accept: "application/json",
          ...(payload === null ? {} : { "Content-Type": "application/json" }),
        },
        body: payload === null ? undefined : JSON.stringify(payload),
        credentials: "omit",
        redirect: "error",
        cache: "no-store",
        referrerPolicy: "no-referrer",
      });
    } catch (_error) {
      throw new Error("Não foi possível consultar a API interna.");
    }
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(errorMap[body.error] || ("A API respondeu HTTP " + response.status + "."));
    return body;
  }

  function buildSliders() {
    for (const [id, [min, max, step]] of Object.entries(sliders)) {
      const original = $(id);
      if (!original) continue;
      const slider = document.createElement("input");
      slider.type = "range";
      slider.id = "slider-" + id;
      slider.className = "range-input";
      slider.min = String(min);
      slider.max = String(max);
      slider.step = String(step);
      slider.value = original.value.trim().replace(",", ".") || String(min);
      slider.setAttribute("aria-label", "Ajustar " + (original.previousElementSibling?.textContent || id));
      original.insertAdjacentElement("afterend", slider);
      slider.addEventListener("input", () => {
        original.value = slider.value;
        onScenarioChange();
      });
      original.addEventListener("input", () => {
        const parsed = Number(original.value.trim().replace(",", "."));
        if (original.value.trim() && Number.isFinite(parsed)) slider.value = String(parsed);
        onScenarioChange();
      });
      slider.addEventListener("change", scheduleRecalculation);
      original.addEventListener("change", scheduleRecalculation);
    }
  }

  function updateSliders() {
    for (const id of Object.keys(sliders)) {
      const slider = $("slider-" + id);
      if (!slider) continue;
      const field = $(id);
      const numeric = Number(field.value.replace(",", "."));
      slider.disabled = field.disabled;
      if (Number.isFinite(numeric)) slider.value = String(numeric);
    }
  }

  function buildCreditFactors(fields) {
    creditFactorCatalog = fields;
    const container = $("credit-factor-fields");
    container.replaceChildren();
    for (const field of fields) {
      const wrapper = document.createElement("div");
      wrapper.className = "form-field";
      const label = document.createElement("label");
      label.htmlFor = "weight-" + field.id;
      label.textContent = field.label + " · " + field.unit;
      const input = document.createElement("input");
      input.type = "text";
      input.inputMode = "decimal";
      input.id = "weight-" + field.id;
      input.value = field.value === null ? "" : String(field.value).replace(".", ",");
      input.placeholder = "Coeficiente necessário";
      const details = document.createElement("small");
      details.textContent = "Origem: " + field.source + " · peso fictício ajustável";
      wrapper.append(label, input, details);
      container.appendChild(wrapper);
      const slider = document.createElement("input");
      slider.type = "range";
      slider.className = "range-input";
      slider.id = "slider-weight-" + field.id;
      slider.min = "0";
      slider.max = field.id === "api_calls" ? "1" : "20";
      slider.step = field.id === "api_calls" ? "0.005" : "0.05";
      slider.value = field.value ?? "0";
      input.insertAdjacentElement("afterend", slider);
      slider.addEventListener("input", () => {
        input.value = slider.value;
        onScenarioChange();
      });
      input.addEventListener("input", () => {
        const candidate = Number(input.value.replace(",", "."));
        if (Number.isFinite(candidate)) slider.value = String(candidate);
        onScenarioChange();
      });
      slider.addEventListener("change", scheduleRecalculation);
      input.addEventListener("change", scheduleRecalculation);
    }
    updateCreditBudget();
  }

  function economicApplies(field) {
    if (field.id === "offer.customer_infrastructure.remote_setup_hours") {
      return model === "customer_infrastructure" && $("remote").checked;
    }
    if (field.id === "offer.customer_infrastructure.remote_maintenance_hours_monthly") {
      return model === "customer_infrastructure" && $("remote").checked && $("maintenance").checked;
    }
    if (field.id === "offer.customer_infrastructure.service_coordination_hours_monthly") {
      return model === "customer_infrastructure" && $("maintenance").checked;
    }
    if (field.id === "simulation.customer_infrastructure.setup_hours") {
      return model === "customer_infrastructure" && $("include-setup").checked;
    }
    if (field.id.includes(".cloud.")) return model === "cloud";
    if (field.id.includes(".managed.")) return model === "managed";
    if (field.id.includes(".customer_infrastructure.")) return model === "customer_infrastructure";
    if (model === "customer_infrastructure" && (field.id.includes("unit_credit_cost") ||
        field.id.includes("platform_per_contract") || field.id.includes("cac_per_new_contract"))) return false;
    return true;
  }

  function renderEconomicControls() {
    for (const field of economicCatalog) {
      const wrapper = $("control-" + field.id.replace(/\./g, "-"))?.closest(".form-field");
      if (wrapper) wrapper.hidden = !economicApplies(field);
    }
  }

  function buildEconomicControls(fields) {
    economicCatalog = fields;
    const target = $("economic-control-fields");
    target.replaceChildren();
    for (const field of fields) {
      const wrapper = document.createElement("div");
      wrapper.className = "form-field";
      const input = document.createElement("input");
      input.id = "control-" + field.id.replace(/\./g, "-");
      input.type = "text";
      input.inputMode = "decimal";
      input.value = field.value ?? "";
      const label = document.createElement("label");
      label.htmlFor = input.id;
      label.textContent = field.label;
      const slider = document.createElement("input");
      slider.type = "range";
      slider.className = "range-input";
      slider.setAttribute("aria-label", "Ajustar " + field.label);
      slider.min = "0";
      const base = Number(field.value ?? 0);
      const max = field.unit === "fraction" ? 1 :
        (field.id.includes("capacity") ? Math.max(100, base * 3) :
          (field.id.includes("setup_hours") || field.id.includes("hours_monthly") ? Math.max(50, base * 3) :
            Math.max(100, base * 3)));
      slider.max = String(max);
      slider.step = field.unit === "fraction" ? "0.005" :
        (field.unit.includes("hour") ? "0.5" : (field.unit.includes("capacity") ? "1" : "0.05"));
      slider.value = field.value ?? "0";
      const description = document.createElement("small");
      description.textContent = field.source + " · " + field.unit + " · valor inicial: " + (field.value ?? "não informado");
      wrapper.append(label, input, slider, description);
      target.appendChild(wrapper);
      slider.addEventListener("input", () => {
        input.value = slider.value;
        onScenarioChange();
      });
      input.addEventListener("input", () => {
        const n = Number(input.value.replace(",", "."));
        if (Number.isFinite(n)) slider.value = String(n);
        onScenarioChange();
      });
      input.addEventListener("change", scheduleRecalculation);
      slider.addEventListener("change", scheduleRecalculation);
    }
    renderEconomicControls();
  }

  function economicInputs() {
    const overrides = {};
    for (const field of economicCatalog) {
      if (!economicApplies(field)) continue;
      const raw = $("control-" + field.id.replace(/\./g, "-")).value.trim().replace(",", ".");
      if (!raw) {
        if (field.value === null) continue;
        throw new Error("Informe o parâmetro " + field.label + ".");
      }
      if (!/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/.test(raw)) {
        throw new Error("Parâmetro inválido: " + field.label);
      }
      if (field.unit === "fraction" && Number(raw) > 1) {
        throw new Error("Fração maior que 1: " + field.label);
      }
      if (field.value === null || Number(raw) !== Number(field.value)) overrides[field.id] = raw;
    }
    return overrides;
  }

  function creditInputs() {
    const usage = {};
    const weights = {};
    for (const field of creditFactorCatalog) {
      usage[field.id] = intInput("usage-" + field.id, 0,
        field.id === "users" || field.id === "storage_gb" ? 100000 : 1000000);
      const raw = $("weight-" + field.id).value.trim().replace(",", ".");
      if (!/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,3})?$/.test(raw)) {
        throw new Error("Confira o peso de " + field.label + ".");
      }
      weights[field.id] = raw;
    }
    if (creditFactorCatalog.length !== creditResources.length) {
      throw new Error("O catálogo não informou todos os pesos de crédito.");
    }
    return {usage, weights};
  }

  function updateCreditBudget() {
    if (!matrix || model === "customer_infrastructure" || !creditFactorCatalog.length) return;
    try {
      const {usage, weights} = creditInputs();
      const allowance = intInput("included", 0, 1000000);
      const contributions = creditResources.map((id) => ({
        id, label: creditFactorCatalog.find((field) => field.id === id).label,
        quantity: usage[id], weight: Number(weights[id]),
        credits: usage[id] * Number(weights[id]),
      }));
      const demanded = Math.ceil(contributions.reduce((sum, item) => sum + item.credits, 0) - 1e-10);
      if (demanded > 1000000) throw new Error("Demanda de créditos excede o limite da simulação.");
      const balance = allowance - demanded;
      set("demand", demanded.toLocaleString("pt-BR") + " créditos");
      set("credit-balance", balance >= 0 ?
        balance.toLocaleString("pt-BR") + " créditos" :
        Math.abs(balance).toLocaleString("pt-BR") + " créditos excedentes");
      set("credits-used", demanded.toLocaleString("pt-BR") + " de " + allowance.toLocaleString("pt-BR") + " créditos");
      const pack = intInput("topup-units", 1, 1000000);
      set("credits-extra", balance < 0 ? Math.ceil(-balance / pack) + " recarga(s) para atender toda a demanda" : "Nenhuma recarga necessária");
      const progress = allowance === 0 ? (demanded ? 100 : 0) : Math.min(100, 100 * demanded / allowance);
      $("credit-fill").style.width = progress + "%";
      $("credit-fill").classList.toggle("overdrawn", balance < 0);
      $("credit-meter").setAttribute("aria-valuenow", String(Math.round(progress)));
      $("credit-breakdown").replaceChildren();
      for (const item of contributions) {
        const line = document.createElement("div");
        const title = document.createElement("span");
        title.textContent = item.label + ": " + item.quantity.toLocaleString("pt-BR") + " × " + item.weight.toLocaleString("pt-BR");
        const result = document.createElement("strong");
        result.textContent = item.credits.toLocaleString("pt-BR", {maximumFractionDigits: 3}) + " créditos";
        line.append(title, result);
        $("credit-breakdown").appendChild(line);
      }
      $("credit-lab-title").closest(".credit-lab").classList.remove("invalid-input");
      return demanded;
    } catch (error) {
      $("credit-lab-title").closest(".credit-lab").classList.add("invalid-input");
      set("credit-balance", "Dados inválidos");
      set("credits-extra", error.message);
      return null;
    }
  }

  function updateLivePrice() {
    const price = Number($("candidate-monthly").value.replace(",", "."));
    const discount = Number($("annual-discount").value.replace(",", "."));
    const enabled = model !== "customer_infrastructure" || $("maintenance").checked;
    const valid = enabled && $("candidate-monthly").value.trim() && Number.isFinite(price) &&
      price >= 0 && Number.isFinite(discount) && discount >= 0 && discount <= 50;
    set("live-monthly", valid ? "R$ " + price.toLocaleString("pt-BR", {minimumFractionDigits:2, maximumFractionDigits:2}) : "A definir");
    set("live-annual", valid ? "R$ " + (price * 12 * (1 - discount / 100)).toLocaleString("pt-BR", {minimumFractionDigits:2,maximumFractionDigits:2}) : "A definir");
    $("live-plan-strip").hidden = !enabled;
  }

  function onScenarioChange() {
    if (!matrix) return;
    changedAt += 1;
    updateCreditBudget();
    updateLivePrice();
    if (!$("outcome").hidden) {
      $("outcome").classList.add("stale-result");
      set("recalculation-status", "Parâmetros alterados. Atualização econômica pendente...");
    }
    scheduleRecalculation();
  }

  function scheduleRecalculation() {
    if (!matrix) return;
    if (pendingRecalculation !== null) window.clearTimeout(pendingRecalculation);
    const remaining = Math.max(0, 3500 - (Date.now() - lastRequestAt));
    pendingRecalculation = window.setTimeout(() => {
      pendingRecalculation = null;
      if (busy) {
        scheduleRecalculation();
      } else {
        $("studio-form").requestSubmit();
      }
    }, Math.max(1400, remaining));
  }

  function setBusy(isBusy) {
    busy = isBusy;
    for (const id of ["connect", "calculate", "reset"]) $(id).disabled = isBusy;
  }

  function renderModel(next) {
    if (matrix && model !== next) {
      changedAt += 1;
      pricesByModel[model] = $("candidate-monthly").value;
    }
    model = next;
    $("candidate-monthly").value = pricesByModel[next] ?? initialPrices[next];
    $("subscription-configuration").hidden = next === "customer_infrastructure";
    $("managed-fields").hidden = next !== "managed";
    $("onprem-fields").hidden = next !== "customer_infrastructure";
    set("product-explanation", productHelp[next]);
    set("accounts-label", next === "cloud" ? "Assinaturas ativas simuladas" : "Contratos equivalentes para projeção");
    set("accounts-help", next === "cloud"
      ? "Teste 10, 100 ou 1.000 contratos. Não é o número de usuários que o comprador precisa informar."
      : "Quantidade hipotética de contratos semelhantes para avaliar custo e escala. O preço exibido é por contrato.");
    document.querySelectorAll(".product-option").forEach((option) => {
      option.classList.toggle("selected", option.dataset.product === next);
    });
    if (next !== "customer_infrastructure") {
      $("accounts").value = next === "cloud" ? "100" : "1";
    } else {
      $("accounts").value = "1";
    }
    toggleMaintenance();
    toggleSetup();
    renderEconomicControls();
    updateSliders();
    renderEconomicControls();
    updateCreditBudget();
    updateLivePrice();
    $("outcome").hidden = true;
    if (matrix) scheduleRecalculation();
  }

  function toggleMaintenance() {
    const noMaintenance = model === "customer_infrastructure" && !$("maintenance").checked;
    if (noMaintenance) $("include-setup").checked = true;
    $("candidate-monthly").disabled = noMaintenance;
    $("annual-discount").disabled = noMaintenance;
    $("onprem-support").disabled = noMaintenance;
    if (noMaintenance) $("candidate-monthly").value = "";
    $("candidate-monthly").closest(".form-field").classList.toggle("disabled-field", noMaintenance);
    $("annual-discount").closest(".form-field").classList.toggle("disabled-field", noMaintenance);
    $("onprem-support").closest(".form-field").classList.toggle("disabled-field", noMaintenance);
    toggleSetup();
  }

  function toggleSetup() {
    const onPrem = model === "customer_infrastructure";
    const needsSetup = !onPrem || $("include-setup").checked;
    const needsAnyInitialService = needsSetup || (onPrem && $("remote").checked);
    for (const id of ["onprem-setup", "onprem-setup-price"]) {
      const enabled = id === "onprem-setup" ? needsSetup : needsAnyInitialService;
      $(id).disabled = !enabled;
      $(id).closest(".form-field").classList.toggle("disabled-field", !enabled);
    }
    if (!needsSetup) $("onprem-setup").value = "0";
    if (!needsAnyInitialService) $("onprem-setup-price").value = "";
  }

  async function connect() {
    if (busy) return;
    setBusy(true);
    $("studio-fields").disabled = true;
    matrix = null;
    $("outcome").hidden = true;
    $("source-strip").hidden = true;
    try {
      labelMessage("Lendo a versão do catálogo...");
      const result = await api("/v1/economic-metadata");
      if (!result.parameters || !result.parameters.version || !result.parameters.sha256 || !result.models) {
        throw new Error("Metadados incompletos: não foi possível identificar a matriz.");
      }
      matrix = result.parameters;
      lastSuccessfulSignature = null;
      const privateFactors = await api("/v1/offer-parameters");
      if (Array.isArray(privateFactors.economic_controls)) buildEconomicControls(privateFactors.economic_controls);
      if (Array.isArray(privateFactors.credit_factors) && privateFactors.credit_factors.length === creditResources.length) {
        buildCreditFactors(privateFactors.credit_factors);
      } else {
        throw new Error("A API privada não retornou todos os pesos de crédito.");
      }
      if (privateFactors.parameters.sha256 !== matrix.sha256) {
        throw new Error("A matriz foi alterada. Recarregue os parâmetros antes de simular.");
      }
      set("catalog-version", matrix.version);
      updateSliders();
      set("catalog-scope", matrix.scope === "SYNTHETIC_ONLY" ? "Inteiramente fictícia" : "Privada");
      set("catalog-approval", matrix.approval_status === "research_only" ? "Pesquisa, sem preços aprovados" : "Declarada no catálogo; exige homologação");
      set("catalog-state", "Matriz identificada");
      $("source-strip").hidden = false;
      $("studio-fields").disabled = false;
      labelMessage("Matriz carregada. Ajuste os controles; os créditos respondem imediatamente.");
      scheduleRecalculation();
    } catch (error) {
      set("catalog-state", "Sem configuração");
      labelMessage(error.message, true);
    } finally {
      setBusy(false);
    }
  }

  function payload() {
    const onPrem = model === "customer_infrastructure";
    const managed = model === "managed";
    const recurring = !onPrem || $("maintenance").checked;
    const includeSetup = !onPrem || $("include-setup").checked;
    const remoteAccess = onPrem && $("remote").checked;
    const input = {
      model,
      parameter_version: matrix.version,
      accounts: intInput("accounts", 1, 1000000),
      credits_per_account: 0,
      resource_usage: onPrem ? null : creditInputs().usage,
      credit_weights: onPrem ? {} : Object.fromEntries(Object.entries(creditInputs().weights).filter(([key, value]) => {
        const catalog = creditFactorCatalog.find((factor) => factor.id === key);
        return catalog?.value === null || Number(value) !== Number(catalog.value);
      })),
      economic_overrides: economicInputs(),
      included_credits: onPrem ? 0 : intInput("included", 0, 1000000),
      topup_credits: onPrem ? 1 : intInput("topup-units", 1, 1000000),
      topup_take_rate: onPrem ? "0" : rateInput("take-rate"),
      annual_discount: rateInput("annual-discount"),
      acquisition_rate: onPrem ? "0" : rateInput("acquisition-rate"),
      candidate_monthly_price: recurring ? moneyInput("candidate-monthly") : null,
      candidate_topup_price: onPrem ? null : moneyInput("topup-price"),
      candidate_setup_price: managed ? moneyInput("managed-setup-price") :
        (onPrem && (includeSetup || remoteAccess) ? moneyInput("onprem-setup-price") : null),
      support_hours: managed ? hoursInput("managed-support") : (onPrem && recurring ? hoursInput("onprem-support") : "0"),
      setup_extra_hours: managed ? hoursInput("managed-setup") : (onPrem && includeSetup ? hoursInput("onprem-setup") : "0"),
      remote_access: remoteAccess,
      recurring_maintenance: !onPrem || recurring,
      include_setup: includeSetup,
    };
    if (!onPrem && updateCreditBudget() !== null &&
        updateCreditBudget() > input.included_credits &&
        input.topup_take_rate !== "0" && input.topup_take_rate !== "0.0000" &&
        input.candidate_topup_price === null) {
      throw new Error("Para calcular recargas pagas, informe também o preço da recarga.");
    }
    return input;
  }

  function appendPairs(id, pairs) {
    const root = $(id);
    root.replaceChildren();
    for (const [term, value, emphasis] of pairs) {
      const line = document.createElement("div");
      if (emphasis) line.className = "emphasis";
      const label = document.createElement("dt");
      const info = document.createElement("dd");
      label.textContent = term;
      info.textContent = value;
      line.append(label, info);
      root.appendChild(line);
    }
  }

  function addCell(tr, contents) {
    const td = document.createElement("td");
    td.textContent = String(contents);
    tr.appendChild(td);
  }

  function showEvidence(result) {
    set("matrix-sha", result.parameters.sha256);
    set("sim-sha", result.simulation_fingerprint);
    const root = $("evidence-rows");
    root.replaceChildren();
    for (const item of result.evidence || []) {
      const row = document.createElement("tr");
      addCell(row, item.id);
      addCell(row, item.value === null ? "Não informado" : String(item.value));
      addCell(row, item.unit);
      addCell(row, item.source);
      addCell(row, item.approval_status === "approved_for_commercial_use" ? "Declarado como aprovado" : "Não aprovado");
      root.appendChild(row);
    }
  }

  function renderPeriod(id, data, type) {
    if (!data) {
      appendPairs(id, [["Resultado", "Informe o preço candidato para analisar receita e retorno."]]);
      return;
    }
    appendPairs(id, [
      ["Receita total estimada por mês", formatMoney(data.gross_revenue), true],
      ["Assinaturas", formatMoney(data.subscription_revenue)],
      ["Recargas simuladas", formatMoney(data.topup_revenue)],
      ["Infraestrutura", formatMoney(data.hosting)],
      ["Plataforma", formatMoney(data.platform)],
      ["Operação e manutenção", formatMoney(data.operations)],
      ["Suporte contratado", formatMoney(data.support)],
      ["Créditos efetivamente atendidos", formatMoney(data.credits_served_cost)],
      ["Aquisição de clientes", formatMoney(data.customer_acquisition)],
      ["Taxas de pagamento", formatMoney(data.payment_cost)],
      ["Tributos e inadimplência", formatMoney(data.tax_and_default)],
      ["Total de custos operacionais", formatMoney(data.operating_cost)],
      ["Resultado mensal estimado", formatMoney(data.net_contribution), true],
      ["Margem observada", data.net_margin_pct === null ? "Indefinida" : localized(data.net_margin_pct) + "%"],
    ]);
  }

  function showCalculated(result) {
    const calc = result.calculation;
    const onPrem = result.input.model === "customer_infrastructure";
    const recurring = calc.recurring_enabled;
    const setupOnly = onPrem && !recurring;
    $("recurring-outputs").hidden = setupOnly;
    $("growth-analysis").hidden = onPrem;
    const headings = setupOnly
      ? ["Piso para implantação", "Custo da implantação", "Implantação candidata", "Saldo no preço candidato"]
      : ["Piso mensal técnico", "Piso anual técnico", "Valor mensal candidato", "Valor anual candidato"];
    ["floor-monthly-label", "floor-annual-label", "price-monthly-label", "price-annual-label"].forEach((id, index) => set(id, headings[index]));
    if (setupOnly) {
      set("floor-monthly", formatMoney(calc.setup_recommended));
      set("floor-annual", formatMoney(calc.setup_cost));
      set("price-monthly", calc.setup_candidate === null ? "A definir" : formatMoney(calc.setup_candidate));
      set("price-annual", calc.setup_net_contribution === null ? "A definir" : formatMoney(calc.setup_net_contribution));
      set("floor-monthly-help", "Referência de implantação única, considerando custos e margem.");
      set("floor-annual-help", "Horas de implantação e acesso autorizado à infraestrutura.");
      set("price-monthly-help", "Preço único testado, não homologado.");
      set("price-annual-help", "Resultado líquido do projeto com o preço informado.");
    } else {
      set("floor-monthly", recurring ? formatMoney(calc.monthly_floor_expected) : "Não se aplica");
      set("floor-annual", recurring ? formatMoney(calc.annual_subscription_floor) : "Não se aplica");
      set("price-monthly", recurring && calc.monthly_candidate !== null ? formatMoney(calc.monthly_candidate) : "A definir");
      set("price-annual", recurring && calc.annual_subscription !== null ? formatMoney(calc.annual_subscription) : "A definir");
      set("floor-monthly-help", "Para o uso previsto, sem receita incerta de recargas.");
      set("floor-annual-help", "Doze meses, com desconto anual e taxas de pagamento.");
      set("price-monthly-help", "Preço fixo testado, não aprovado.");
      set("price-annual-help", "Total da assinatura anual, sem recargas extras.");
    }

    const economics = [];
    if (!onPrem) {
      economics.push(
        ["Piso se toda a franquia for usada", formatMoney(calc.monthly_floor_full_allowance)],
        ["Piso de uma recarga completa", formatMoney(calc.topup_reference_floor)],
        ["Preço candidato da recarga", calc.topup_price === null ? "Não informado" : formatMoney(calc.topup_price)],
      );
    }
    if (recurring) economics.push(["Equivalente mensal no plano anual", formatMoney(calc.annual_effective_monthly)]);
    if (result.input.model === "cloud") {
      economics.push(["Implantação humana", "Não incluída: contratação automatizada"]);
    } else if (calc.setup_recommended !== null) {
      economics.push(
        ["Preço mínimo estimado da implantação", formatMoney(calc.setup_recommended)],
        ["Custo direto do trabalho", formatMoney(calc.setup_cost)],
        ["Horas de implantação", localized(calc.setup_hours) + " h"],
        ["Custo horário usado na matriz", formatMoney(calc.setup_formula?.hourly_cost)],
        ["Cálculo das horas", localized(calc.setup_formula?.hours) + " × " + formatMoney(calc.setup_formula?.hourly_cost)],
        ["Taxa fixa de pagamento", formatMoney(calc.setup_formula?.payment_fixed)],
        ["Tributos estimados", localized(Number(calc.setup_formula?.tax_rate) * 100) + "%"],
        ["Inadimplência estimada", localized(Number(calc.setup_formula?.default_rate) * 100) + "%"],
        ["Taxa percentual de pagamento", localized(Number(calc.setup_formula?.payment_rate) * 100) + "%"],
        ["Margem planejada", localized(Number(calc.setup_formula?.target_margin) * 100) + "%"],
        ["Preço candidato da implantação", calc.setup_candidate === null ? "Não informado" : formatMoney(calc.setup_candidate)],
      );
      if (calc.setup_net_contribution !== null) {
        economics.push(["Saldo no projeto candidato", formatMoney(calc.setup_net_contribution)]);
      }
    } else {
      economics.push(["Implantação", "Não contratada; não é cobrada"]);
    }
    appendPairs("economic-summary", economics);

    const selected = calc.sensitivity.find((p) => p.accounts === result.input.accounts);
    const usage = [
      ["Modalidade", productNames[result.input.model]],
      ["Contratos considerados", String(result.input.accounts)],
    ];
    if (onPrem) {
      usage.push(
        ["Hospedagem", "Responsabilidade do cliente"],
        ["Contrato de manutenção", recurring ? "Mensal" : "Não incluído"],
        ["Suporte contratado por cliente", recurring ? localized(result.input.support_hours) + " h/mês" : "Não incluído"],
        ["Acesso autorizado à infraestrutura", result.input.remote_access ? "Sim" : "Não"],
      );
      if (recurring) usage.push(["Custo interno mensal de operação", formatMoney(selected?.total_operating_cost)]);
    } else {
      usage.push(
        ["Instâncias de infraestrutura necessárias", String(selected?.hosting_nodes ?? "—")],
        ["Franquia mensal por contrato", result.input.included_credits + " unidades provisórias"],
        ["Demanda média calculada", calc.average_credits_requested + " unidades"],
        ["Consumo efetivamente atendido", Number(calc.average_credits_served).toLocaleString("pt-BR", { maximumFractionDigits: 2 }) + " unidades/contrato"],
        ["Pacotes adicionais para toda a demanda", String(calc.additional_packs_if_fully_recharged)],
        ["Proporção de compradores de recarga", localized(Number(result.input.topup_take_rate) * 100) + "%"],
        ["Custo de operação por mês", formatMoney(selected?.total_operating_cost)],
      );
    }
    appendPairs("consumption-summary", usage);
    renderPeriod("monthly-result", calc.periods && calc.periods.monthly, "monthly");
    renderPeriod("annual-result", calc.periods && calc.periods.annual, "annual");

    const root = $("sensitivity");
    root.replaceChildren();
    for (const variant of calc.sensitivity) {
      const row = document.createElement("tr");
      if (variant.accounts === result.input.accounts) row.className = "selected-row";
      addCell(row, variant.accounts);
      addCell(row, onPrem ? "Cliente" : variant.hosting_nodes + " instância(s)");
      addCell(row, formatMoney(variant.required_monthly_price));
      addCell(row, formatMoney(variant.required_monthly_price_full_allowance));
      addCell(row, variant.periods.monthly === null ? "Preço a definir" : formatMoney(variant.periods.monthly.net_contribution));
      root.appendChild(row);
    }
    $("calculated").hidden = false;
    $("incomplete").hidden = true;
  }

  function showResult(result) {
    $("outcome").hidden = false;
    set("scenario-caption", productNames[result.input.model] + " · " + result.input.accounts + " contratos simulados · matriz " + result.parameters.version);
    showEvidence(result);
    if (result.status === "incomplete") {
      $("calculated").hidden = true;
      $("incomplete").hidden = false;
      const list = $("missing-parameters");
      list.replaceChildren();
      for (const id of result.missing_parameters || []) {
        const item = document.createElement("li");
        item.textContent = id;
        list.appendChild(item);
      }
      labelMessage("Coeficientes econômicos ausentes. O preço não pode ser estimado com segurança.", true);
      return;
    }
    if (result.status !== "calculated" || !result.calculation) throw new Error("Resposta incompleta do simulador.");
    showCalculated(result);
    labelMessage("Projeção concluída. Confira os custos, a capacidade e as fontes antes de decidir o preço.");
  }

  async function calculate(event) {
    event.preventDefault();
    if (pendingRecalculation !== null) {
      window.clearTimeout(pendingRecalculation);
      pendingRecalculation = null;
    }
    if (busy) return;
    if (!matrix) return labelMessage("Carregue a matriz econômica primeiro.", true);
    let data;
    try {
      data = payload();
    } catch (error) {
      return labelMessage(error.message, true);
    }
    const signature = JSON.stringify(data);
    if (signature === lastSuccessfulSignature && !$("outcome").hidden) {
      $("outcome").classList.remove("stale-result");
      set("recalculation-status", "Sem alterações econômicas desde o último cálculo.");
      return;
    }
    const currentVersion = changedAt;
    setBusy(true);
    try {
      lastRequestAt = Date.now();
      labelMessage("Calculando preço e custos com os parâmetros selecionados...");
      const result = await api("/v1/offer-simulations", "POST", data);
      if (currentVersion !== changedAt) return;
      showResult(result);
      lastSuccessfulSignature = signature;
      $("outcome").classList.remove("stale-result");
      set("recalculation-status", "Preços atualizados com o motor econômico da API.");
      if (matrix.sha256 !== result.parameters.sha256) {
        labelMessage("A matriz mudou durante a análise; recarregue-a e confira o SHA-256.", true);
      }
    } catch (error) {
      $("outcome").hidden = true;
      labelMessage(error.message, true);
    } finally {
      setBusy(false);
      if (currentVersion !== changedAt) scheduleRecalculation();
    }
  }

  function reset() {
    if (busy) return;
    pricesByModel[model] = initialPrices[model];
    $("candidate-monthly").value = initialPrices[model];
    $("annual-discount").value = "0";
    $("included").value = "100";
    $("demand").value = "100";
    $("topup-units").value = "50";
    $("topup-price").value = "";
    $("take-rate").value = "0";
    $("acquisition-rate").value = "0";
    $("managed-support").value = "2";
    $("managed-setup").value = "0";
    $("managed-setup-price").value = "";
    $("onprem-support").value = "2";
    $("onprem-setup").value = "0";
    $("onprem-setup-price").value = "";
    $("maintenance").checked = true;
    $("include-setup").checked = true;
    $("remote").checked = false;
    for (const field of economicCatalog) {
      const input = $("control-" + field.id.replace(/\./g, "-"));
      if (input) input.value = field.value ?? "";
    }
    const initial = {signed_obligations:55, users:5, storage_gb:20, api_calls:1000, tsa_documents:10};
    for (const key of creditResources) {
      $("usage-" + key).value = String(initial[key]);
      const field = creditFactorCatalog.find((factor) => factor.id === key);
      if (field && $("weight-" + key)) $("weight-" + key).value = String(field.value ?? "");
    }
    renderModel(model);
    updateSliders();
    renderEconomicControls();
    updateLivePrice();
    labelMessage("Hipóteses restauradas. A matriz permanece carregada.");
    scheduleRecalculation();
  }

  document.querySelectorAll('input[name="product"]').forEach((input) => {
    input.addEventListener("change", () => renderModel(input.value));
  });
  $("maintenance").addEventListener("change", toggleMaintenance);
  $("include-setup").addEventListener("change", () => {
    if (!$("include-setup").checked && !$("maintenance").checked) $("maintenance").checked = true;
    toggleMaintenance();
  });
  $("remote").addEventListener("change", () => { toggleSetup(); renderEconomicControls(); });
  $("connect").addEventListener("click", connect);
  $("studio-form").addEventListener("submit", calculate);
  $("reset").addEventListener("click", reset);
  buildSliders();
  document.querySelectorAll('input[name="product"], #maintenance, #include-setup, #remote')
    .forEach((element) => element.addEventListener("change", () => {
      updateSliders();
      renderEconomicControls();
      updateCreditBudget();
      updateLivePrice();
      if (matrix) scheduleRecalculation();
    }));
  renderModel(model);
})();
