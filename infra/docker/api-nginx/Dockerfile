FROM nginx:1.31.2-alpine3.23@sha256:54f2a904c251d5a34adf545a72d32515a15e08418dae0266e23be2e18c66fefa

RUN rm /etc/nginx/conf.d/default.conf
COPY infra/docker/api-nginx/default.conf /etc/nginx/conf.d/default.conf
COPY apps/api/public /app/public

EXPOSE 8080
