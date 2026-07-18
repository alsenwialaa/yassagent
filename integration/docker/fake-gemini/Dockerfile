FROM node:24.18.0-bookworm-slim
WORKDIR /app
COPY integration/fake-gemini/server.js /app/server.js
USER node
EXPOSE 8787
CMD ["node", "/app/server.js"]
