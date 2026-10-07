# Feature: ajustes-plantillas-y-pedido

## Objective
Fix four issues reported by the user after the carteras deploy.

## Problem / why
- SAP DTW rejects the header when `DocDueDate` is empty (it is required); asesores sometimes leave `fecha_facturacion` blank.
- The address template lacks `ShipToCounty` and `ShipToCountry`.
- The "Por aprobar" list shows the total with IVA; approvers want the value without IVA.
- `direccion_2` and `observaciones` have no length limit; SAP needs at most 60 and 250 characters.

## Scope / constraints
- `DocDueDate` falls back to the order creation date (`created_at`) when `fecha_facturacion` is null. The review panel shows the same value.
- Address template columns: `DocEntry, ShipToStreet, ShipToCity, ShipToCounty, ShipToCountry`; both new columns are the fixed value `CO` (user decision 2026-10-07).
- Bandeja "Por aprobar" shows `subtotal` with a small caption "Valor sin IVA".
- `direccion_2` max 60, `observaciones` max 250: HTML `maxlength` plus server-side validation in the order component save path.

## Tasks
- [x] T1 Plantillas: DocDueDate fallback + ShipToCounty/ShipToCountry = CO (route: delegated writer, 3+ files)
- [x] T2 Bandeja: subtotal sin IVA with caption (route: same writer)
- [x] T3 Pedido form: 60/250 character limits (route: same writer)

## Acceptance criteria
- Tests cover each behavior; full suite green; `npm run build` done for new classes.

## Delivery strategy
ask-on-risk; forecast well under 400 authored lines.

## Progress / evidence
- Branch `fix/plantillas-y-pedido-ajustes` from main d058b02.
- Writer verification: PlantillasSap 44/44, Bandejas 30/30, GuardarYSalir|LimitesEncabezado 9/9, full suite 343/343, npm run build OK. Parent spot check: full suite 343/343.
