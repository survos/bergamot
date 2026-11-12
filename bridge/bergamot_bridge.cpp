// Narrow C boundary: C++ objects and exceptions never cross into Python.
// Reuses exported engine symbols from QVAC 10.0.0's macOS ARM64 dylib.
#include "bergamot.hpp"
#include <dlfcn.h>
#include <cstdlib>
#include <cstring>
#include <stdexcept>

namespace {
thread_local std::string last_error;
struct Engine {
  void* library = nullptr;
  int workers = 1;
  bergamot_context* context = nullptr;
  decltype(&bergamotInit) init = nullptr;
  decltype(&bergamotTranslate) translate = nullptr;
  decltype(&bergamotGetRuntimeStats) stats = nullptr;
  decltype(&bergamotResetRuntimeStats) reset = nullptr;
  decltype(&bergamotFree) free_context = nullptr;
  ~Engine() {
    if (context && free_context) free_context(context);
    if (library) dlclose(library);
  }
};
template<class T> T symbol(void* library, const char* name) {
  auto ptr = dlsym(library, name);
  if (!ptr) throw std::runtime_error(std::string("Missing native symbol: ") + name);
  return reinterpret_cast<T>(ptr);
}
}
extern "C" {
const char* bn_error() noexcept { return last_error.c_str(); }
void* bn_load(const char* library, const char* model, const char* vocab) noexcept {
  try {
    last_error.clear();
    auto engine = std::make_unique<Engine>();
    engine->library = dlopen(library, RTLD_LAZY | RTLD_LOCAL);
    if (!engine->library) throw std::runtime_error(dlerror());
    engine->init = symbol<decltype(engine->init)>(engine->library, "_Z12bergamotInitPKcRK15bergamot_params");
    engine->translate = symbol<decltype(engine->translate)>(engine->library, "_Z17bergamotTranslateP16bergamot_contextPKc");
    engine->stats = symbol<decltype(engine->stats)>(engine->library, "_Z23bergamotGetRuntimeStatsP16bergamot_contextPdS1_Pi");
    engine->reset = symbol<decltype(engine->reset)>(engine->library, "_Z25bergamotResetRuntimeStatsP16bergamot_context");
    engine->free_context = symbol<decltype(engine->free_context)>(engine->library, "_Z12bergamotFreeP16bergamot_context");
    bergamot_params params;
    params.model_path = model;
    params.src_vocab_path = vocab;
    params.dst_vocab_path = vocab;
    params.cache_size = 0;
    engine->workers = symbol<int(*)()>(engine->library, "_Z24get_optimal_thread_countv")();
    params.num_workers = engine->workers;
    params.beam_size = 1;
    params.normalize = 1;
    engine->context = engine->init(model, params);
    if (!engine->context) throw std::runtime_error("Bergamot initialization failed");
    return engine.release();
  } catch (const std::exception& e) { last_error = e.what(); }
    catch (...) { last_error = "Unknown native initialization failure"; }
  return nullptr;
}
char* bn_translate(void* handle, const char* input, double* seconds, int* tokens) noexcept {
  try {
    last_error.clear();
    auto* engine = static_cast<Engine*>(handle);
    engine->reset(engine->context);
    const auto text = engine->translate(engine->context, input);
    double encode = 0, decode = 0;
    engine->stats(engine->context, &encode, &decode, tokens);
    *seconds = encode + decode;
    if (text.empty()) throw std::runtime_error("Empty translation");
    auto* copy = static_cast<char*>(std::malloc(text.size() + 1));
    if (!copy) throw std::bad_alloc();
    std::memcpy(copy, text.c_str(), text.size() + 1);
    return copy;
  } catch (const std::exception& e) { last_error = e.what(); }
    catch (...) { last_error = "Unknown native translation failure"; }
  return nullptr;
}
int bn_workers(void* handle) noexcept { return static_cast<Engine*>(handle)->workers; }
void bn_string_free(char* text) noexcept { std::free(text); }
void bn_free(void* handle) noexcept { delete static_cast<Engine*>(handle); }
}
