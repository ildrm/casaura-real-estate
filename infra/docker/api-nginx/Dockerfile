FROM nginx:1.31.0-alpine3.23@sha256:2f07d83bf561b506400dc183b1b2003803e39efbd22451f848adaba14d28c7c7

RUN rm /etc/nginx/conf.d/default.conf
COPY infra/docker/api-nginx/default.conf /etc/nginx/conf.d/default.conf
COPY apps/api/public /app/public

EXPOSE 8080
