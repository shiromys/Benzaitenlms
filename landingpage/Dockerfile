# Static server for the landing pages (nginx — same server family as the Moodle service).
# Railway builds this when the service's Root Directory = landingpage
FROM nginx:alpine
# Railway injects PORT; default to 8080 locally. Only substitute PORT so nginx's own $uri etc. stay intact.
ENV PORT=8080
ENV NGINX_ENVSUBST_FILTER=PORT
COPY default.conf.template /etc/nginx/templates/default.conf.template
COPY site/ /usr/share/nginx/html

