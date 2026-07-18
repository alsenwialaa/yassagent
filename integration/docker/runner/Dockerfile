FROM mcr.microsoft.com/playwright:v1.61.1-noble
WORKDIR /runner
COPY package.json package-lock.json /runner/
RUN npm ci --ignore-scripts && npm cache clean --force
ENV NODE_PATH=/runner/node_modules
WORKDIR /workspace/plugin
CMD ["node", "/runner/node_modules/@playwright/test/cli.js", "test", "-c", "/workspace/plugin/integration/tests/playwright.config.js"]
