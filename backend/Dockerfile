FROM mcr.microsoft.com/playwright:v1.64.1-noble
WORKDIR /app
ARG SYNDAMORPH_INSTALL_SINGLEFILE=0
COPY package.json ./
RUN npm install --omit=dev --ignore-scripts && chown -R pwuser:pwuser /app
COPY reference-dependencies/package.json reference-dependencies/package-lock.json /app/reference-dependencies/
RUN if [ "${SYNDAMORPH_INSTALL_SINGLEFILE}" = "1" ]; then \
      npm ci --prefix /app/reference-dependencies --omit=dev --ignore-scripts; \
    fi
RUN install -d -m 0700 -o pwuser -g pwuser /var/lib/syndamorph-references
COPY --chown=pwuser:pwuser server.js capture.js sections.js diagnostics.js production-source.js production-reference.js production-touch.js production-header.js recorded-footer.js recorded-newsletter.js reference-fetch.js responsive-capture.js purr-poc.js ./
COPY --chown=pwuser:pwuser mvp ./mvp
USER pwuser
EXPOSE 3100
CMD ["node", "server.js"]
