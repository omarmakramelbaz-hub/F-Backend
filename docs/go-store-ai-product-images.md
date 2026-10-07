# Automatic unbranded GO product images

GO Partner signup products require only a name and price. The app posts `storefront.auto_images: true` and uploads the store logo through the existing verified upload session. Existing clients that supply product photos remain compatible.

The application is saved immediately, including every product and its price. No external provider call runs in signup or approval. The existing Laravel scheduler runs `go-stores:find-product-images` in the background. OpenAI identifies the generic product and writes the image search query; Brave supplies real image candidates; OpenAI vision verifies the actual downloaded pixels before those same bytes are saved. Branded packaging, brand names, logos, readable text and watermarks are rejected. The merchant's original product name and price are preserved.

Provider errors retry up to three times. No confident unbranded match results in `no_match`, with an empty image rather than a guessed photo. Pending products can be approved; subsequent successful images attach automatically. Manual image or product name edits are never overwritten. Application review exposes `image_status`, and the database retains source URLs and AI confidence.

## Activation order

1. Review and deploy the backend changes against the server's actual current version. Do not reset the server to a repository snapshot: the deployed ERP may have additional changes.
2. Configure these values in the server environment or `.env`, never in GitHub source, app code or chat:

   ```dotenv
   GO_PRODUCT_IMAGES_ENABLED=true
   GO_PRODUCT_IMAGES_OPENAI_KEY=
   GO_PRODUCT_IMAGES_BRAVE_KEY=
   GO_PRODUCT_IMAGES_MODEL=gpt-4.1-mini
   ```

3. Run `bash deployment/launch_go_product_images.sh`. This applies only the new image-request table and clears configuration caches. Confirm that the server's existing `php artisan schedule:run` cron is active.
4. Submit a test store with name/price-only products. Run `php artisan go-stores:find-product-images --limit=1`. Review the real selected image for product match and absence of branding before releasing the app changes.
5. Deploy GO Partner after the backend accepts `auto_images` signup submissions. Android and iOS releases require their usual app distribution steps.

Setting `GO_PRODUCT_IMAGES_ENABLED=false` stops provider calls and preserves requests for later processing. Keep the new table and existing images to preserve approved catalogs. Provider usage is separate from a ChatGPT subscription.

## Validation

Automated tests fake providers; they cover 60 products with only a logo upload, retry/idempotency, approval before image completion, preservation of names/prices/options, no-match results, provider failure, manual edits, and explicit rejection of branded or low-confidence images. A live provider smoke test requires the server credentials above; it cannot be inferred from mocked tests.

API references: [OpenAI image inputs](https://developers.openai.com/api/docs/guides/images-vision), [OpenAI structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses), [Brave image search](https://api-dashboard.search.brave.com/api-reference/images/image_search).
