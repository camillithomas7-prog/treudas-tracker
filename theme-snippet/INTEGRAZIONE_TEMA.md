# Integrazione tracker nel tema TREUDAS

Questo file documenta come integrare il tracker nel tema Shopify **senza rompere niente** del tema esistente.

## File da copiare

Copiare `tracker.js` in `assets/tracker.js` del tema Shopify.

## Modifica unica al tema

In `layout/theme.liquid` (o `templates/theme.liquid` se la struttura è custom), aggiungere **una sola riga** prima del `</body>`:

```liquid
<script src="{{ 'tracker.js' | asset_url }}" defer></script>
```

In alternativa, se vuoi anche un session_id propagato come variabile globale all'apertura della pagina (utile per debug), aggiungere prima dello script:

```liquid
<script>
  window.TREUDAS_PAGE_TYPE = "{{ template.name }}";
</script>
```

## Configurazione

Aprire `tracker.js` e modificare la prima costante con il dominio reale del tracker:

```js
var TREUDAS_TRACKER_URL = 'https://track.treudasofficial.com/api/track.php';
```

## Cosa serve in più (opzionale ma consigliato)

### CTA con posizione tracciata

Per sapere quale dei 6 CTA viene cliccato, aggiungere `data-cta-position="N"` ai link CTA in `templates/index.liquid`:

```liquid
<a href="/products/treudas" class="cta cta-primary" data-cta-position="1">
```

Senza questa modifica, il tracker registra comunque il click ma non sa quale CTA era.

### UTM su tutti i link CTA (opzionale)

Per distinguere i clienti che vengono dall'advertorial vs quelli che arrivano direttamente al prodotto:

```liquid
<a href="/products/treudas?from=advertorial" class="cta cta-primary" data-cta-position="1">
```

## Webhook Shopify (per tracciare gli acquisti)

In **Shopify Admin → Settings → Notifications → Webhooks**:

1. Click **Create webhook**
2. Event: **Order payment**
3. Format: **JSON**
4. URL: `https://track.treudasofficial.com/api/webhook.php`
5. Salva
6. Copia il **secret** che Shopify mostra una volta sola
7. Incollalo in `config.php` del tracker → `shopify_webhook_secret`

## session.js esistente

`tracker.js` è **compatibile** con `session.js` già presente nel tema:
- Usa la stessa chiave localStorage `treudas_session_v1`
- Quindi il `session_id` salvato in cart attributes/order properties coincide con quello inviato al tracker
- Risultato: gli acquisti via webhook si collegano automaticamente alle sessioni tracciate

**NON è necessario modificare `session.js`** né altri file del tema.

## Verifica funzionamento

Dopo il deploy:
1. Apri lo store → DevTools → Network
2. Filtra "track.php"
3. Naviga: advertorial → click CTA → prodotto → carrello
4. Devi vedere 1 richiesta `track.php` per ogni passo
5. Aprire la dashboard `track.treudasofficial.com` e vedere il funnel popolato
