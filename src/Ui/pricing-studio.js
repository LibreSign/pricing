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

  function setBusy(isBusy) {
    busy = isBusy;
    for (const id of ["connect", "calculate", "reset"]) $(id).disabled = isBusy;
  }

  function renderModel(next) {
    model = next;
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
    $("outcome").hidden = true;
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
      set("catalog-version", matrix.version);
      set("catalog-scope", matrix.scope === "SYNTHETIC_ONLY" ? "Inteiramente fictícia" : "Privada");
      set("catalog-approval", matrix.approval_status === "research_only" ? "Pesquisa, sem preços aprovados" : "Declarada no catálogo; exige homologação");
      set("catalog-state", "Matriz identificada");
      $("source-strip").hidden = false;
      $("studio-fields").disabled = false;
      labelMessage("Matriz carregada. Desenhe a oferta e execute a simulação.");
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
      credits_per_account: onPrem ? 0 : intInput("demand", 0, 1000000),
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
    if (!onPrem && input.credits_per_account > input.included_credits &&
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
        ["Piso da implantação única", formatMoney(calc.setup_recommended)],
        ["Custo interno da implantação", formatMoney(calc.setup_cost)],
        ["Horas de implantação", localized(calc.setup_hours) + " h"],
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
        ["Demanda média declarada", result.input.credits_per_account + " unidades"],
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
    if (busy) return;
    if (!matrix) return labelMessage("Carregue a matriz econômica primeiro.", true);
    let data;
    try {
      data = payload();
    } catch (error) {
      return labelMessage(error.message, true);
    }
    setBusy(true);
    try {
      labelMessage("Simulando capacidade, impostos, custos e preços...");
      const result = await api("/v1/offer-simulations", "POST", data);
      showResult(result);
      if (matrix.sha256 !== result.parameters.sha256) {
        labelMessage("A matriz mudou durante a análise; recarregue-a e confira o SHA-256.", true);
      }
    } catch (error) {
      $("outcome").hidden = true;
      labelMessage(error.message, true);
    } finally {
      setBusy(false);
    }
  }

  function reset() {
    if (busy) return;
    $("candidate-monthly").value = "";
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
    renderModel(model);
    labelMessage("Hipóteses restauradas. A matriz permanece carregada.");
  }

  document.querySelectorAll('input[name="product"]').forEach((input) => {
    input.addEventListener("change", () => renderModel(input.value));
  });
  $("maintenance").addEventListener("change", toggleMaintenance);
  $("include-setup").addEventListener("change", () => {
    if (!$("include-setup").checked && !$("maintenance").checked) $("maintenance").checked = true;
    toggleMaintenance();
  });
  $("remote").addEventListener("change", toggleSetup);
  $("connect").addEventListener("click", connect);
  $("studio-form").addEventListener("submit", calculate);
  $("reset").addEventListener("click", reset);
  renderModel(model);
})();
