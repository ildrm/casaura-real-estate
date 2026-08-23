FROM nginx:1.29.4-alpine3.23@sha256:4870c12cd2ca986de501a804b4f506ad3875a0b1874940ba0a2c7f763f1855b2

RUN rm /etc/nginx/conf.d/default.conf
COPY infra/docker/api-nginx/default.conf /etc/nginx/conf.d/default.conf
COPY apps/api/public /app/public

EXPOSE 8080
