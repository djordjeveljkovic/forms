# Lightweight wrapper around the official mysql image that adds `dcron` so the
# backup container can run the crontab file. Built only when the `backup`
# profile is enabled.
FROM mysql:8.4

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends dcron \
    && rm -rf /var/lib/apt/lists/*

USER mysql
