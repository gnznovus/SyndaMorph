FROM node:22-alpine
WORKDIR /app
COPY --chown=node:node gateway/core.js gateway/store.js gateway/server.js ./gateway/
RUN mkdir -p /data /artifacts && chown node:node /data /artifacts
USER node
EXPOSE 3200
CMD ["node", "gateway/server.js"]
