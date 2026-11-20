FROM mcr.microsoft.com/playwright:v1.64.1-noble
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev --ignore-scripts && chown -R pwuser:pwuser /app
COPY --chown=pwuser:pwuser server.js capture.js sections.js ./
USER pwuser
EXPOSE 3100
CMD ["node", "server.js"]
