# RUN_SOON

## Reclaim :prev image space (~28.95 GB)

After confirming all 8 sites are running normally on `joinery-base:1.0` (scheduled tasks firing, users logging in), remove the `:prev` rollback images on docker-prod:

```bash
for site in joinerydemo empoweredhealthtn getjoinery jeremytunnell mapsofwisdom galactictribune phillyzouk scrolldaddy; do
    docker rmi joinery-$site:prev
done
```

Then move `specs/docker_shared_base_image.md` to `specs/implemented/`.
